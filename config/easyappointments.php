<?php

return [
    // Seconds to wait for Easy!Appointments before giving up on a request.
    'timeout' => (int) env('EA_HTTP_TIMEOUT', 10),

    // Services, providers and the instance time zone change rarely: cache them.
    'catalog_cache_seconds' => (int) env('EA_CATALOG_CACHE_SECONDS', 600),

    // Open slots go stale fast, but a short cache saves a lot of calls.
    // Easy!Appointments allows only 100 requests per IP every 120 seconds by default.
    'availability_cache_seconds' => (int) env('EA_AVAILABILITY_CACHE_SECONDS', 60),

    // The most availability lookups one find_open_slots call may make (providers x days).
    'max_availability_lookups' => (int) env('EA_MAX_AVAILABILITY_LOOKUPS', 21),

    // How long a used idempotency key replays its first result.
    'idempotency_hours' => 24,

    // How long call logs are kept.
    'log_retention_days' => (int) env('MCP_LOG_RETENTION_DAYS', 90),

    // MCP requests per minute per person.
    'requests_per_minute' => (int) env('MCP_REQUESTS_PER_MINUTE', 60),
];
