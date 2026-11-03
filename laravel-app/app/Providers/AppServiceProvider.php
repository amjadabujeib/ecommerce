<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Sign-in endpoints: limited per account and IP so password guessing is
        // slowed down without locking out everyone behind a shared address.
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(6)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
