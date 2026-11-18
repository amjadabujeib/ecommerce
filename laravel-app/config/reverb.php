<?php

return [
    'default' => 'reverb',
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8081),
            'path' => '',
            'hostname' => env('REVERB_HOST'),
            'options' => ['tls' => []],
            'max_request_size' => 10_000,
            'scaling' => ['enabled' => false],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID', 'novacart'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8081),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            // Host names as required by Reverb; no wildcard production origin.
            'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('REVERB_ALLOWED_ORIGINS', 'localhost,127.0.0.1'))))),
            'ping_interval' => 30,
            'activity_timeout' => 30,
            'max_connections' => 100,
            'max_message_size' => 10_000,
            'max_channels_per_connection' => 100,
            // No client-originated application events are required by this project.
            'accept_client_events_from' => 'none',
            'rate_limiting' => [
                'enabled' => true,
                'max_attempts' => 120,
                'decay_seconds' => 60,
                'terminate_on_limit' => true,
            ],
        ]],
    ],
];
