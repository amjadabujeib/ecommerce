<?php

return [
    'public_host' => env('REVERB_PUBLIC_HOST'),
    'public_port' => env('REVERB_PUBLIC_PORT'),
    'public_scheme' => env('REVERB_PUBLIC_SCHEME', parse_url((string) env('APP_URL', 'http://localhost:8080'), PHP_URL_SCHEME)),
];
