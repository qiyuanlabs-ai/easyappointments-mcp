<?php

namespace App\Mcp\Tools;

use App\Ea\EaApiException;
use App\Ea\EaClient;
use App\Ea\EaRequestLog;
use App\Enums\AccessLevel;
use App\Models\McpCall;
use App\Models\User;
use App\Support\Mask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Base for every Easy!Appointments tool: checks the access level, builds the API client
 * for the person's connection, turns failures into clear messages and logs every call.
 */
abstract class EaTool extends Tool
{
    /** The access level a connection needs to see and call this tool. */
    protected AccessLevel $requires = AccessLevel::Read;

    abstract protected function perform(Request $request, EaClient $ea, User $user): ToolResult;

    /**
     * Before sign-in, every tool is listed so clients and directories can see what the server
     * offers; calling one still needs a token. After sign-in, tools the connection may not use
     * are hidden, so the AI never sees them.
     */
    public function shouldRegister(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return true;
        }

        return $user instanceof User && $this->levelFor($user)->allows($this->requires);
    }

    public function handle(Request $request, EaRequestLog $log): Response|ResponseFactory
    {
        $started = hrtime(true);
        $log->reset();
        $user = $request->user();
        $result = null;
        $error = null;
        $outcome = 'error';

        try {
            if (! $user instanceof User) {
                throw new ToolFailure('Not signed in.');
            }

            if (! $this->levelFor($user)->allows($this->requires)) {
                $outcome = 'denied';
                throw new ToolFailure('This connection only has '.$this->levelFor($user)->label().' access. If their account allows it, the person can raise this connection to Front desk at '.url('/dashboard').' and then ask again. Reconnecting is not needed.');
            }

            $connection = $user->eaConnection;

            if ($connection === null) {
                throw new ToolFailure('This account is not linked to an Easy!Appointments instance yet. An administrator needs to link it.');
            }

            $result = $this->perform($request, new EaClient($connection, $log), $user);
            $outcome = $result->outcome;
        } catch (ValidationException $e) {
            $error = collect($e->errors())->flatten()->implode(' ');
        } catch (ToolFailure|EaApiException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $error = 'Something went wrong on the MCP server. The error was logged; try again or contact the administrator.';
        }

        $this->record($request, $user, $outcome, $error, $result, $log, $started);

        return $error !== null ? Response::error($error) : Response::structured($result->data);
    }

    protected function levelFor(User $user): AccessLevel
    {
        return $user->accessLevelForClient($this->clientId($user));
    }

    protected function clientId(User $user): ?string
    {
        $clientId = $user->token()?->oauth_client_id;

        return $clientId !== null ? (string) $clientId : null;
    }

    private function record(Request $request, ?User $user, string $outcome, ?string $error, ?ToolResult $result, EaRequestLog $log, int|float $started): void
    {
        try {
            $clientId = $user ? $this->clientId($user) : null;

            McpCall::create([
                'user_id' => $user?->id,
                'oauth_client_id' => $clientId,
                'client_name' => $clientId !== null ? Passport::client()->newQuery()->find($clientId)?->name : null,
                'tool' => $this->name(),
                'arguments' => Mask::arguments($request->all()),
                'outcome' => $outcome,
                'error' => $error !== null ? Mask::text($error) : null,
                'ea_requests' => $log->entries(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                'appointment_id' => $result?->appointmentId,
                'idempotency_key' => $request->get('idempotency_key'),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // A logging failure must never break the tool call itself.
        }
    }
}
