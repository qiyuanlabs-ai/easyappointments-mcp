<?php

namespace App\Mcp\Tools;

use App\Ea\EaApiException;
use App\Ea\EaClient;
use App\Enums\AccessLevel;
use App\Models\IdempotencyKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Throwable;

/**
 * A tool that changes Easy!Appointments. Every call runs in two steps:
 *
 * 1. Without confirm: check everything and return a preview plus a confirmation_token.
 *    Nothing changes. The token is signed, bound to this person, connection, tool and
 *    exact arguments, and expires after a few minutes, so a change cannot skip its preview.
 * 2. With confirm: true, that token and an idempotency_key: claim the key first (so two calls
 *    with the same key can never both act), then, holding a lock on the provider's day, check
 *    the slot again with fresh data and make the change. The result is stored on the key, so
 *    a retried call returns it instead of acting twice.
 */
abstract class WriteTool extends EaTool
{
    protected AccessLevel $requires = AccessLevel::FrontDesk;

    /** How long a claimed key may stay unfinished before we stop assuming the first call is still running. */
    private const PENDING_SECONDS = 120;

    /** Requests a change may send while holding its slot lock: the fresh check, the writes and a clean-up. */
    private const MAX_REQUESTS_UNDER_LOCK = 6;

    abstract protected function plan(Request $request, EaClient $ea, bool $confirming): PlannedChange;

    /** The input fields this tool adds to confirm, confirmation_token and idempotency_key. */
    abstract protected function fields(JsonSchema $schema): array;

    public function schema(JsonSchema $schema): array
    {
        return $this->fields($schema) + [
            'confirm' => $schema->boolean()->description('Leave false (the default) to get a preview without changing anything. Set true only after the person has seen the preview and agreed.'),
            'confirmation_token' => $schema->string()->description('Required when confirm is true: the confirmation_token from the preview of exactly these arguments. It expires after a few minutes; if it has, get a new preview.'),
            'idempotency_key' => $schema->string()->description('Required when confirm is true. Any unique string, for example a UUID. Reusing it with the same arguments returns the first result instead of acting twice.'),
        ];
    }

    protected function perform(Request $request, EaClient $ea, User $user): ToolResult
    {
        $request->validate([
            'confirm' => ['sometimes', 'boolean'],
            'confirmation_token' => ['required_if_accepted:confirm', 'nullable', 'string', 'max:200'],
            'idempotency_key' => ['required_if_accepted:confirm', 'nullable', 'string', 'min:8', 'max:100'],
        ], [
            'confirmation_token.required_if_accepted' => 'confirmation_token is required when confirm is true. Call '.$this->name().' without confirm first, show the person the preview, and pass the confirmation_token it returns.',
            'idempotency_key.required_if_accepted' => 'idempotency_key is required when confirm is true. Use any unique string, such as a UUID.',
        ]);

        $hash = $this->argumentsHash($request);

        if (! $request->get('confirm', false)) {
            $change = $this->plan($request, $ea, false);
            $expires = now()->addMinutes(config('easyappointments.confirmation_minutes'));

            return ToolResult::preview($change->preview + [
                'confirmation_token' => $this->confirmationToken($user, $hash, $expires->getTimestamp()),
                'confirmation_expires_at' => $expires->toIso8601String(),
                'next_step' => 'Nothing has changed yet. Show this to the person. If they agree, call '.$this->name().' again with the same arguments plus confirm: true, this confirmation_token and a new idempotency_key.',
            ], $change->appointmentId);
        }

        $expiresAt = $this->verifyConfirmationToken($user, $hash, (string) $request->get('confirmation_token'));
        $key = (string) $request->get('idempotency_key');

        if (($existing = $this->existingKey($user, $key)) !== null) {
            return $this->answerFromExisting($existing, $hash);
        }

        if ($expiresAt < now()->getTimestamp()) {
            throw new ToolFailure('The preview has expired. Call '.$this->name().' again without confirm, show the person the new preview, and confirm with its confirmation_token.');
        }

        try {
            $claim = IdempotencyKey::create([
                'user_id' => $user->id,
                'key' => $key,
                'tool' => $this->name(),
                'arguments_hash' => $hash,
                'result' => null,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another call with this key claimed it a moment ago.
            return $this->answerFromExisting($this->existingKey($user, $key) ?? throw new ToolFailure('Try again in a moment.'), $hash);
        }

        $outcomeUnknown = false;

        try {
            $change = $this->plan($request, $ea, true);
            $result = $this->underLock($change->lock, function () use ($change, &$outcomeUnknown) {
                if ($change->recheck !== null) {
                    ($change->recheck)();
                }

                try {
                    return ($change->execute)();
                } catch (EaApiException $e) {
                    // No answer at all: Easy!Appointments may still have made the change.
                    $outcomeUnknown = $e->status === null;
                    throw $e;
                }
            });
        } catch (Throwable $e) {
            if ($outcomeUnknown) {
                throw new ToolFailure('Easy!Appointments did not answer, so it is not clear whether the change was made. Check with list_appointments before trying again with a new idempotency_key.');
            }

            $claim->delete(); // Nothing changed, so the key may be used again.
            throw $e;
        }

        $claim->update(['result' => $result, 'completed_at' => now()]);

        return new ToolResult($result, 'ok', $result['appointment']['id'] ?? $change->appointmentId);
    }

    /**
     * How long a slot lock lives if its holder dies. It must outlast the slowest change: the
     * fresh slot check and every write under the lock may each take the full Easy!Appointments
     * timeout, so the lock lasts that many timeouts plus a margin.
     */
    public static function lockSeconds(): int
    {
        return max(1, (int) config('easyappointments.timeout')) * self::MAX_REQUESTS_UNDER_LOCK + 30;
    }

    /** Runs the change while holding the lock on its slot, so changes to one provider's day run one at a time. */
    private function underLock(?string $name, Closure $callback): array
    {
        if ($name === null) {
            return $callback();
        }

        try {
            return Cache::lock('slot:'.$name, self::lockSeconds())->block(config('easyappointments.slot_lock_wait_seconds'), $callback);
        } catch (LockTimeoutException) {
            throw new ToolFailure('Another change to the same provider and day is in progress. Try again in a few seconds.');
        }
    }

    private function answerFromExisting(IdempotencyKey $existing, string $hash): ToolResult
    {
        if ($existing->tool !== $this->name() || $existing->arguments_hash !== $hash) {
            throw new ToolFailure('This idempotency_key was already used for a different request. Use a new key for a new change.');
        }

        if (! $existing->isCompleted()) {
            throw new ToolFailure($existing->created_at->gt(now()->subSeconds(max(self::PENDING_SECONDS, self::lockSeconds())))
                ? 'A call with this idempotency_key is still being processed. Wait a few seconds, then call again with the same key to get its result.'
                : 'An earlier call with this idempotency_key did not finish, so it is not clear whether the change was made. Check with list_appointments before trying again with a new idempotency_key.');
        }

        return new ToolResult(['replayed' => true] + $existing->result, 'replayed', $existing->result['appointment']['id'] ?? null);
    }

    private function confirmationToken(User $user, string $hash, int $expires): string
    {
        return $expires.'.'.$this->signature($user, $hash, $expires);
    }

    /** Checks the token was issued for this person, connection, tool and arguments, and returns when it expires. */
    private function verifyConfirmationToken(User $user, string $hash, string $token): int
    {
        [$expires, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! ctype_digit($expires) || ! hash_equals($this->signature($user, $hash, (int) $expires), $signature)) {
            throw new ToolFailure('This confirmation_token does not belong to a preview of exactly these arguments. Call '.$this->name().' without confirm, show the person the preview, and confirm with the confirmation_token it returns.');
        }

        return (int) $expires;
    }

    private function signature(User $user, string $hash, int $expires): string
    {
        $payload = implode('|', ['confirm', $user->id, $this->clientId($user) ?? '-', $this->name(), $hash, $expires]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** Parses a "Y-m-d H:i" time in the instance's time zone and insists it is in the future. */
    protected function futureTime(string $value, string $timezone, string $field): CarbonImmutable
    {
        $time = CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, $timezone);

        if ($time === false || $time->format('Y-m-d H:i') !== $value) {
            throw new ToolFailure("$field must look like 2026-11-04 14:30 (in $timezone).");
        }

        if ($time->isPast()) {
            throw new ToolFailure("$field ($value $timezone) is in the past.");
        }

        return $time;
    }

    /**
     * Confirms a start time is open for the provider. On the confirming call the cache is
     * skipped, because a slot can be taken between the preview and the confirmation.
     */
    protected function assertSlotOpen(EaClient $ea, array $provider, array $service, CarbonImmutable $start, bool $fresh): void
    {
        $date = $start->format('Y-m-d');

        if ($fresh) {
            $ea->forgetAvailability($provider['id'], $date);
        }

        $open = $ea->availability($provider['id'], $service['id'], $date);

        if (! in_array($start->format('H:i'), $open, true)) {
            $nearest = collect($open)
                ->sortBy(fn (string $t) => abs(CarbonImmutable::parse("$date $t", $start->timezone)->diffInMinutes($start, false)))
                ->take(3)->sort()->values()->all();

            throw new ToolFailure(sprintf(
                '%s is not open for %s with %s on %s.%s',
                $start->format('H:i'), $service['name'], $provider['name'], $date,
                $nearest === [] ? ' There are no open times that day.' : ' Nearest open times that day: '.implode(', ', $nearest).'.',
            ));
        }
    }

    /** One lock per provider and day: every change that books time in it runs alone. */
    protected function slotLock(EaClient $ea, int $providerId, CarbonImmutable $start): string
    {
        return $ea->connectionId().':'.$providerId.':'.$start->format('Y-m-d');
    }

    protected function serviceOffered(EaClient $ea, int $serviceId, int $providerId): array
    {
        $service = $ea->service($serviceId) ?? throw new ToolFailure("No service with id $serviceId. Use list_services to see the ids.");
        $provider = $ea->provider($providerId) ?? throw new ToolFailure("No provider with id $providerId. Use list_services to see who offers what.");

        if (! in_array($service['id'], $provider['services'], true)) {
            throw new ToolFailure("{$provider['name']} does not offer {$service['name']}. Use list_services to see who does.");
        }

        return [$service, $provider];
    }

    private function argumentsHash(Request $request): string
    {
        $arguments = collect($request->all())->except(['confirm', 'idempotency_key', 'confirmation_token'])->sortKeys()->all();

        return hash('sha256', json_encode($arguments));
    }

    private function existingKey(User $user, string $key): ?IdempotencyKey
    {
        $existing = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->first();

        if ($existing !== null && $existing->created_at->lt(now()->subHours(config('easyappointments.idempotency_hours')))) {
            $existing->delete(); // Expired: the key may be used again.

            return null;
        }

        return $existing;
    }
}
