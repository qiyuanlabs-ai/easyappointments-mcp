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

        Passport::authorizationView(fn (array $parameters) => view('consent', $parameters + [
            'levels' => AccessLevel::cases(),
        ]));

        Passport::tokensExpireIn(now()->addHours(1));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(config('easyappointments.requests_per_minute'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
