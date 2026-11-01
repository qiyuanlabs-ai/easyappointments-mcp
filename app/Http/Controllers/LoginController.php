<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Counted per IP and, separately, per account, so guesses spread over many IPs
        // still run into a limit for the account they target.
        $limits = [
            'login:'.$request->ip() => [10, 60],
            'login-account:'.hash('sha256', mb_strtolower($credentials['email'])) => [10, 600],
        ];

        foreach ($limits as $key => [$attempts, $seconds]) {
            if (RateLimiter::tooManyAttempts($key, $attempts)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                throw ValidationException::withMessages(['email' => "Too many attempts. Try again in $minutes ".($minutes === 1 ? 'minute' : 'minutes').'.']);
            }
        }

        if (! Auth::attempt($credentials)) {
            foreach ($limits as $key => [$attempts, $seconds]) {
                RateLimiter::hit($key, $seconds);
            }

            throw ValidationException::withMessages(['email' => 'Those details do not match an account.']);
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::clear($key);
        }
        $request->session()->regenerate();

        // Back to the OAuth consent screen when the login was started by an AI client.
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
