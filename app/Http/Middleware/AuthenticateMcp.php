<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lazy authentication for the MCP endpoint (see the MCP authorization spec and Claude's
 * "lazy authentication" guide). A client may connect and list tools without a token, so
 * it and directories can see what the server offers. Anything else, above all tools/call,
 * needs a valid token and otherwise gets HTTP 401 with WWW-Authenticate, which makes the
 * client start the OAuth sign-in and retry. A token that is sent is always checked.
 */
class AuthenticateMcp
{
    /** JSON-RPC methods that reveal nothing about anyone's data. */
    private const PUBLIC_METHODS = ['initialize', 'notifications/initialized', 'notifications/cancelled', 'ping', 'tools/list'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null && $this->onlyPublicMethods($request)) {
            return $next($request);
        }

        $guard = Auth::guard('api');

        if (! $guard->check()) {
            throw new AuthenticationException('Unauthenticated.', ['api']);
        }

        Auth::shouldUse('api');

        return $next($request);
    }

    private function onlyPublicMethods(Request $request): bool
    {
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || $body === []) {
            return false;
        }

        $messages = array_is_list($body) ? $body : [$body];

        foreach ($messages as $message) {
            if (! is_array($message) || ! in_array($message['method'] ?? null, self::PUBLIC_METHODS, true)) {
                return false;
            }
        }

        return true;
    }
}
