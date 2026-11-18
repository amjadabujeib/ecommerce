<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'log'),
    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID', 'novacart'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8081),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                'connect_timeout' => 2,
                'timeout' => 5,
                // Container restarts can change Reverb's IP while this worker lives.
                'curl' => defined('CURLOPT_DNS_CACHE_TIMEOUT')
                    ? [constant('CURLOPT_DNS_CACHE_TIMEOUT') => 0]
                    : [],
            ],
        ],
        'log' => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],
];
