<?php

return [
    'enabled' => (bool) env('PERFORMANCE_ENABLED', false),
    'replica' => (bool) env('READ_REPLICA_ENABLED', false),
    'catalog_cache' => (bool) env('CATALOG_CACHE_ENABLED', false),
    'catalog_ttl' => (int) env('CATALOG_CACHE_TTL', 60),
    'cache_store' => env('CATALOG_CACHE_STORE', 'redis'),
    // Never bypass production authentication or the login limiter.
    'load_testing' => (bool) env('PERFORMANCE_LOAD_TESTING', false),
    'diagnostics' => (bool) env('PERFORMANCE_DIAGNOSTICS', false),
];
