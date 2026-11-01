<?php

namespace App\Providers;

use App\Ea\EaRequestLog;
use App\Enums\AccessLevel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One log per MCP request, so each tool call records only its own Easy!Appointments calls.
        $this->app->scoped(EaRequestLog::class);
    }

    public function boot(): void
    {
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        Passport::authorizationView(function (array $parameters) {
            // Passport has already checked redirect_uri against the client's registered ones.
            $client = $parameters['client'];
            $redirect = (string) ($parameters['request']->query('redirect_uri') ?: ($client->redirect_uris[0] ?? ''));
            $host = mb_strtolower((string) (parse_url($redirect, PHP_URL_HOST) ?: $redirect));

            return view('consent', $parameters + [
                'levels' => AccessLevel::cases(),
                'redirectHost' => $host,
                'knownClient' => collect(config('easyappointments.known_client_hosts'))
                    ->contains(fn (string $known) => $host === $known || str_ends_with($host, '.'.$known)),
            ]);
        });

        Passport::tokensExpireIn(now()->addHours(1));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        RateLimiter::for('oauth-register', fn (Request $request) => Limit::perHour(config('easyappointments.registrations_per_hour'))
            ->by($request->ip())
            ->response(fn () => response()->json([
                'error' => 'too_many_requests',
                'error_description' => 'Too many client registrations from this address. Try again later.',
            ], 429)));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(config('easyappointments.requests_per_minute'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
