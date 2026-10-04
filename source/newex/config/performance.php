<?php

return [
    'cache_store' => env('PERFORMANCE_CACHE_STORE', 'redis'),

    'wallets_ttl_seconds' => (int) env('PERFORMANCE_WALLETS_TTL_SECONDS', 30),
    'futures_open_ttl_seconds' => (int) env('PERFORMANCE_FUTURES_OPEN_TTL_SECONDS', 30),
    'dashboard_fresh_seconds' => (int) env('PERFORMANCE_DASHBOARD_FRESH_SECONDS', 30),
    'dashboard_stale_seconds' => (int) env('PERFORMANCE_DASHBOARD_STALE_SECONDS', 300),
    'schema_ttl_seconds' => (int) env('PERFORMANCE_SCHEMA_TTL_SECONDS', 86400),

    'futures_open_gate' => [
        'enabled' => filter_var(env('FUTURES_OPEN_GATE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'max_concurrency' => (int) env('FUTURES_OPEN_MAX_CONCURRENCY', 8),
        'wait_milliseconds' => (int) env('FUTURES_OPEN_WAIT_MILLISECONDS', 3000),
        'lease_milliseconds' => (int) env('FUTURES_OPEN_LEASE_MILLISECONDS', 60000),
        'redis_connection' => env('FUTURES_OPEN_REDIS_CONNECTION', 'default'),
    ],
];
