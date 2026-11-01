@extends('layout')
@section('width', 'narrow')
@section('title', 'Connect '.$client->name)
@section('content')
    <h1>Connect {{ $client->name }}?</h1>
    <p class="muted">{{ $client->name }} wants to use the booking system for <strong>{{ $user->eaConnection?->name ?? 'your account' }}</strong> as {{ $user->email }}.</p>

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
        @if ($redirect = request()->query('redirect_uri'))
            <p class="small muted">You will be sent back to <span class="mono">{{ parse_url($redirect, PHP_URL_HOST) ?: $redirect }}</span>.</p>
        @endif
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
