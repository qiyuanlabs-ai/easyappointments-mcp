@extends('layout')
@section('width', 'narrow')
@section('title', 'Connect '.$client->name)
@section('content')
    <h1>Connect {{ $client->name }}?</h1>
    <p class="muted">An app calling itself <strong>{{ $client->name }}</strong> <span class="pill denied">name not verified</span> wants to use the booking system for <strong>{{ $user->eaConnection?->name ?? 'your account' }}</strong> as {{ $user->email }}.</p>

    <div class="card destination {{ $knownClient ? '' : 'unknown' }}">
        <p class="small muted">After you allow it, you will be sent to</p>
        <p class="host mono">{{ $redirectHost }}</p>
        @if (! $knownClient)
            <p class="warning"><strong>This is not a known AI assistant address.</strong> Any app can choose its own name. Only allow this if you started the connection yourself just now and you recognise {{ $redirectHost }}. If not, choose Deny.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('consent.approve') }}" class="card">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        <p><strong>What may it do?</strong></p>
        @foreach ($levels as $level)
            @php($allowed = $user->max_access_level->allows($level))
            <label class="choice" @style(['opacity: .5' => ! $allowed])>
                <input type="radio" name="access_level" value="{{ $level->value }}" @checked($level === \App\Enums\AccessLevel::Read) @disabled(! $allowed)>
                <span><strong>{{ $level->label() }}</strong><br><span class="small muted">{{ $level->summary() }}@if (! $allowed) Your account is not allowed this level.@endif</span></span>
            </label>
        @endforeach

        <p class="small muted">You can change or remove this any time on your dashboard. Every call is logged there.</p>
        {{-- Deny submits its own form (below) but sits next to Allow: secondary on the left, primary on the right. --}}
        <div class="actions">
            <button type="submit" class="quiet" form="deny-form">Deny</button>
            <button type="submit">Allow</button>
        </div>
    </form>

    <form id="deny-form" method="POST" action="{{ route('passport.authorizations.deny') }}" hidden>
        @csrf
        @method('DELETE')
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
    </form>
@endsection
