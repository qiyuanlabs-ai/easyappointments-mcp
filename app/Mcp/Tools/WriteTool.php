<?php

namespace App\Mcp\Tools;

use App\Ea\EaClient;
use App\Enums\AccessLevel;
use App\Models\IdempotencyKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Mcp\Request;

/**
 * A tool that changes Easy!Appointments. Every call runs in two steps:
 *
 * 1. Without confirm: check everything and return a preview. Nothing changes.
 * 2. With confirm: true and an idempotency_key: check again, make the change,
 *    and remember the result so a retried call with the same key cannot book twice.
 */
abstract class WriteTool extends EaTool
{
    protected AccessLevel $requires = AccessLevel::FrontDesk;

    abstract protected function plan(Request $request, EaClient $ea, bool $confirming): PlannedChange;

    /** The input fields this tool adds to confirm and idempotency_key. */
    abstract protected function fields(JsonSchema $schema): array;

    public function schema(JsonSchema $schema): array
    {
        return $this->fields($schema) + [
            'confirm' => $schema->boolean()->description('Leave false (the default) to get a preview without changing anything. Set true only after the person has seen the preview and agreed.'),
            'idempotency_key' => $schema->string()->description('Required when confirm is true. Any unique string, for example a UUID. Reusing it with the same arguments returns the first result instead of acting twice.'),
        ];
    }

    protected function perform(Request $request, EaClient $ea, User $user): ToolResult
    {
        $request->validate([
            'confirm' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required_if_accepted:confirm', 'nullable', 'string', 'min:8', 'max:100'],
        ], [
            'idempotency_key.required_if_accepted' => 'idempotency_key is required when confirm is true. Use any unique string, such as a UUID.',
        ]);

        $confirming = (bool) $request->get('confirm', false);

        if (! $confirming) {
            $change = $this->plan($request, $ea, false);

            return ToolResult::preview($change->preview + [
                'next_step' => 'Nothing has changed yet. Show this to the person. If they agree, call '.$this->name().' again with the same arguments plus confirm: true and a new idempotency_key.',
            ], $change->appointmentId);
        }

        $key = (string) $request->get('idempotency_key');
        $hash = $this->argumentsHash($request);
        $existing = $this->existingKey($user, $key);

        if ($existing !== null) {
            if ($existing->tool !== $this->name() || $existing->arguments_hash !== $hash) {
                throw new ToolFailure('This idempotency_key was already used for a different request. Use a new key for a new change.');
            }

            return new ToolResult(['replayed' => true] + $existing->result, 'replayed', $existing->result['appointment']['id'] ?? null);
        }

        $change = $this->plan($request, $ea, true);
        $result = ($change->execute)();

        try {
            IdempotencyKey::create([
                'user_id' => $user->id,
                'key' => $key,
                'tool' => $this->name(),
                'arguments_hash' => $hash,
                'result' => $result,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel retry with the same key finished first; our change is already made, so report it.
        }

        return new ToolResult($result, 'ok', $result['appointment']['id'] ?? $change->appointmentId);
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
        $arguments = collect($request->all())->except(['confirm', 'idempotency_key'])->sortKeys()->all();

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
