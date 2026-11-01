@extends('layout')
@section('width', 'narrow')
@section('title', 'Sign in')
@section('content')
    <h1>Sign in</h1>
    <p class="muted">Sign in with your account on this server to connect an AI assistant.</p>
    <form method="POST" action="{{ url('/login') }}" class="card">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        @error('email') <p class="errors">{{ $message }}</p> @enderror
        <p style="margin-top:16px"><button type="submit">Sign in</button></p>
    </form>
@endsection
