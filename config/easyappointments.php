<?php

return [
    // Seconds to wait for Easy!Appointments before giving up on a request.
    'timeout' => (int) env('EA_HTTP_TIMEOUT', 10),

    // Services, providers and the instance time zone change rarely: cache them.
    'catalog_cache_seconds' => (int) env('EA_CATALOG_CACHE_SECONDS', 600),

    // Open slots go stale fast, but a short cache saves a lot of calls.
    // Easy!Appointments allows only 100 requests per IP every 120 seconds by default.
    'availability_cache_seconds' => (int) env('EA_AVAILABILITY_CACHE_SECONDS', 60),

    // Requests this server may send to one Easy!Appointments instance per window, shared by
    // everyone using it. Easy!Appointments itself allows 100 per IP every 120 seconds.
    'request_budget' => (int) env('EA_REQUEST_BUDGET', 90),
    'request_budget_seconds' => (int) env('EA_REQUEST_BUDGET_SECONDS', 120),

    // The most availability lookups one find_open_slots call may make (providers x days).
    'max_availability_lookups' => (int) env('EA_MAX_AVAILABILITY_LOOKUPS', 21),

    // How long a used idempotency key replays its first result.
    'idempotency_hours' => 24,

    // How long a preview's confirmation_token stays valid.
    'confirmation_minutes' => (int) env('MCP_CONFIRMATION_MINUTES', 10),

    // How long a change waits for another change to the same provider and day to finish.
    'slot_lock_wait_seconds' => (int) env('MCP_SLOT_LOCK_WAIT_SECONDS', 15),

    // Registered OAuth clients that never got a token are removed after this many days, and
    // clients nobody has used for unused_client_days are removed with their grants.
    'unused_client_days' => (int) env('MCP_UNUSED_CLIENT_DAYS', 90),
    'never_used_client_days' => (int) env('MCP_NEVER_USED_CLIENT_DAYS', 1),

    // Dynamic client registrations allowed per IP address per hour.
    'registrations_per_hour' => (int) env('MCP_REGISTRATIONS_PER_HOUR', 10),

    // Redirect hosts the consent page treats as known MCP clients. Any other host gets a warning.
    'known_client_hosts' => array_filter(array_map('trim', explode(',', env('MCP_KNOWN_CLIENT_HOSTS', 'claude.ai,claude.com,chatgpt.com,localhost,127.0.0.1,[::1]')))),

    // How long call logs are kept.
    'log_retention_days' => (int) env('MCP_LOG_RETENTION_DAYS', 90),

    // MCP requests per minute per person.
    'requests_per_minute' => (int) env('MCP_REQUESTS_PER_MINUTE', 60),
];
