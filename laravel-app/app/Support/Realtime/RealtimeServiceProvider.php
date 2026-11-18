<?php

namespace App\Support\Realtime;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;

final class RealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (config('messaging.enabled') && interface_exists(ChannelConnectionManager::class)) {
            $this->app->bind(ChannelConnectionManager::class, AuthorizedConnections::class);
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1/realtime')->group(function (): void {
                Route::get('/config', [RealtimeController::class, 'configuration']);
                Route::post('/auth', [RealtimeController::class, 'authorizeChannel'])->middleware('auth:api');
            });
        }
    }
}
