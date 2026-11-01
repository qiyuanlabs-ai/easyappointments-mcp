<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Models\McpCall;
use App\Models\McpGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Where a person sees which AI clients they connected, what each may do, and every call made. */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'connection' => $user->eaConnection,
            'grants' => $user->grants()->latest('updated_at')->get(),
            'levels' => AccessLevel::cases(),
            'calls' => $this->calls($request)->paginate(25)->withQueryString(),
            'tools' => McpCall::where('user_id', $user->id)->distinct()->orderBy('tool')->pluck('tool'),
            'filters' => $request->only(['tool', 'outcome']),
        ]);
    }

    public function updateGrant(Request $request, McpGrant $grant): RedirectResponse
    {
        abort_unless($grant->user_id === $request->user()->id, 404);
        $level = $request->validate(['access_level' => ['required', Rule::enum(AccessLevel::class)]])['access_level'];
        $grant->update(['access_level' => AccessLevel::from($level)->cap($request->user()->max_access_level)]);

        return back()->with('status', "{$grant->client_name} now has {$grant->access_level->label()} access.");
    }

    public function revokeGrant(Request $request, McpGrant $grant): RedirectResponse
    {
        abort_unless($grant->user_id === $request->user()->id, 404);

        Token::where('user_id', $grant->user_id)->where('client_id', $grant->oauth_client_id)->get()
            ->each(fn (Token $token) => $token->revoke() && $token->refreshToken?->revoke());
        $grant->delete();

        return back()->with('status', "Disconnected {$grant->client_name}. It has to sign in again to use the server.");
    }

    public function export(Request $request): StreamedResponse
    {
        $calls = $this->calls($request);

        return response()->streamDownload(function () use ($calls) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time_utc', 'client', 'tool', 'outcome', 'appointment_id', 'duration_ms', 'ea_requests', 'arguments', 'error']);
            $calls->lazy()->each(fn (McpCall $call) => fputcsv($out, array_map(self::csvCell(...), [
                $call->created_at->toIso8601String(), $call->client_name, $call->tool, $call->outcome,
                $call->appointment_id, $call->duration_ms, count($call->ea_requests ?? []),
                json_encode($call->arguments), $call->error,
            ])));
            fclose($out);
        }, 'mcp-calls.csv', ['Content-Type' => 'text/csv']);
    }

    /** Client names come from dynamic registration, so a cell must never start a spreadsheet formula. */
    private static function csvCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function calls(Request $request)
    {
        return McpCall::where('user_id', $request->user()->id)
            ->when($request->query('tool'), fn ($q, $tool) => $q->where('tool', $tool))
            ->when($request->query('outcome'), fn ($q, $outcome) => $q->where('outcome', $outcome))
            ->latest('id');
    }
}
