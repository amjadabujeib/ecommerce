<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storefront settings
    |--------------------------------------------------------------------------
    */

    'currency' => 'USD',
    'currency_symbol' => '$',

    /*
    |--------------------------------------------------------------------------
    | Default administrator
    |--------------------------------------------------------------------------
    |
    | Credentials are read from the environment. When ADMIN_PASSWORD is empty
    | the seeder generates a strong random password and prints it to the
    | console. Never commit a real password to the repository.
    |
    */

    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
