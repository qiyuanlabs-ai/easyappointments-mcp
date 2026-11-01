@extends('layout')
@section('content')
    <h1>Easy!Appointments MCP server</h1>
    <p class="muted">A community MCP server that lets Claude, ChatGPT and other AI assistants find open slots and book, move or cancel appointments in an Easy!Appointments booking system, with OAuth sign-in, per-connection permissions and a log of every call.</p>

    <div class="card">
        <p><strong>Server URL</strong> for your AI client:</p>
        <p class="mono">{{ url('/mcp') }}</p>
        <p class="small muted">Add it as a custom connector. Your client will open a sign-in page here, then ask what it may do.</p>
    </div>

    @if (config('app.demo_login'))
        <div class="card">
            <p><strong>Demo account</strong>: <span class="mono">{{ config('app.demo_login.email') }}</span> / <span class="mono">{{ config('app.demo_login.password') }}</span></p>
            <p class="small muted">It is connected to a fictional physio clinic. The data resets every night.</p>
        </div>
    @endif

    <div class="row">
        @auth
            <a class="button" href="{{ route('dashboard') }}">Your connections and call log</a>
        @else
            <a class="button" href="{{ route('login') }}">Sign in</a>
        @endauth
    </div>

    <p class="small muted" style="margin-top:32px">Not affiliated with or endorsed by Easy!Appointments. Easy!Appointments is a trademark of its owner.</p>
@endsection
