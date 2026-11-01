@extends('layout')
@section('title', 'Dashboard')
@section('content')
    <div class="row" style="justify-content: space-between">
        <div>
            <h1>Your connections</h1>
            <p class="muted">Signed in as {{ $user->email }}. Booking system: {{ $connection?->name ?? 'not linked yet' }}.</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf <button class="quiet">Sign out</button></form>
    </div>

    @if (session('status'))
        <div class="card flash">{{ session('status') }}</div>
    @endif

    <div class="card">
        <p><strong>Server URL</strong>: <span class="mono">{{ url('/mcp') }}</span></p>
        @if ($grants->isEmpty())
            <p class="muted">No AI client connected yet. Add the URL above as a custom connector in Claude or ChatGPT.</p>
        @else
            <div class="scroll"><table>
                <tr><th>Client</th><th>Access</th><th>Since</th><th></th></tr>
                @foreach ($grants as $grant)
                    <tr>
                        <td>{{ $grant->client_name }}</td>
                        <td>
                            <form method="POST" action="{{ route('grants.update', $grant) }}" class="row">
                                @csrf @method('PATCH')
                                <select name="access_level" onchange="this.form.submit()" style="width:auto">
                                    @foreach ($levels as $level)
                                        <option value="{{ $level->value }}" @selected($grant->access_level === $level) @disabled(! $user->max_access_level->allows($level))>{{ $level->label() }}</option>
                                    @endforeach
                                </select>
                                <noscript><button class="quiet">Save</button></noscript>
                            </form>
                        </td>
                        <td class="muted">{{ $grant->created_at->diffForHumans() }}</td>
                        <td>
                            <form method="POST" action="{{ route('grants.revoke', $grant) }}">@csrf @method('DELETE') <button class="danger">Disconnect</button></form>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="row" style="justify-content: space-between; margin-top: 32px">
        <h2 style="margin:0">Call log</h2>
        <form method="GET" class="row">
            <select name="tool" style="width:auto">
                <option value="">All tools</option>
                @foreach ($tools as $tool)<option @selected(($filters['tool'] ?? '') === $tool)>{{ $tool }}</option>@endforeach
            </select>
            <select name="outcome" style="width:auto">
                <option value="">All outcomes</option>
                @foreach (['ok', 'preview', 'replayed', 'denied', 'error'] as $outcome)<option @selected(($filters['outcome'] ?? '') === $outcome)>{{ $outcome }}</option>@endforeach
            </select>
            <button class="quiet">Filter</button>
            <a class="button quiet" href="{{ route('calls.export', $filters) }}">Export CSV</a>
        </form>
    </div>
    <p class="small muted">Emails, phone numbers, names and notes are masked. Kept for {{ config('easyappointments.log_retention_days') }} days.</p>

    <div class="card scroll">
        @if ($calls->isEmpty())
            <p class="muted">No calls yet.</p>
        @else
            <table>
                <tr><th>When (UTC)</th><th>Client</th><th>Tool</th><th>Outcome</th><th>Appointment</th><th>Details</th></tr>
                @foreach ($calls as $call)
                    <tr>
                        <td class="mono">{{ $call->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $call->client_name }}</td>
                        <td class="mono">{{ $call->tool }}</td>
                        <td><span class="pill {{ $call->outcome }}">{{ $call->outcome }}</span></td>
                        <td>{{ $call->appointment_id ? '#'.$call->appointment_id : '' }}</td>
                        <td>
                            <details>
                                <summary>{{ $call->duration_ms }} ms, {{ count($call->ea_requests ?? []) }} EA calls</summary>
                                @if ($call->error)<p class="error small">{{ $call->error }}</p>@endif
                                <p class="small"><strong>Arguments</strong></p>
                                <pre class="mono small" style="white-space:pre-wrap">{{ json_encode($call->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                <p class="small"><strong>Easy!Appointments requests</strong></p>
                                @foreach ($call->ea_requests ?? [] as $req)
                                    <div class="mono small">{{ $req['method'] }} {{ $req['path'] }} → {{ $req['status'] ?? 'no response' }} ({{ $req['ms'] }} ms)</div>
                                @endforeach
                            </details>
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:12px">{{ $calls->withQueryString()->links('partials.pager') }}</div>
        @endif
    </div>
@endsection
