<?php

use App\Mcp\Servers\EasyAppointmentsServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth discovery (.well-known) and dynamic client registration for Claude, ChatGPT and other MCP clients.
Mcp::oauthRoutes();

Mcp::web('/mcp', EasyAppointmentsServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
