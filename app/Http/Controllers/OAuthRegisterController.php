<?php

namespace App\Http\Controllers;

use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController as McpOAuthRegisterController;

/**
 * Dynamic client registration, with stricter redirect URIs than the package allows:
 * https anywhere, plain http only back to this computer (loopback, for desktop apps),
 * no custom schemes, no credentials and no fragment (RFC 6749 section 3.1.2).
 */
class OAuthRegisterController extends McpOAuthRegisterController
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    protected function isValidRedirectUri(string $value): bool
    {
        $parts = parse_url($value);

        if ($parts === false || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || str_contains($value, '#')) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || ! parent::isValidRedirectUri($value)) {
            return false;
        }

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, self::LOOPBACK, true));
    }
}
