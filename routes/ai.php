<?php

use App\Http\Controllers\OAuthRegisterController;
use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\Servers\EasyAppointmentsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// OAuth discovery (.well-known) for Claude, ChatGPT and other MCP clients.
Mcp::oauthRoutes(registration: false);

// Dynamic client registration: open to anyone, so rate limited per IP, with strict redirect URIs.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:oauth-register')
    ->name('mcp.oauth.register');

// initialize and tools/list work without a token; every tool call needs one (see AuthenticateMcp).
Mcp::web('/mcp', EasyAppointmentsServer::class)
    ->middleware([AuthenticateMcp::class, 'throttle:mcp']);
