<?php

namespace App\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base provider for the four application modules (Users, Products, Orders,
 * Notifications). Each module registers its own routes and wiring, so the
 * application only needs to list the module providers.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Every module serves its endpoints under the same versioned API prefix.
     */
    public const string API_PREFIX = 'api/v1';

    /**
     * Load the module's routes file into the "api" middleware group.
     */
    protected function loadApiRoutes(string $path): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix(self::API_PREFIX)
            ->group($path);
    }
}
