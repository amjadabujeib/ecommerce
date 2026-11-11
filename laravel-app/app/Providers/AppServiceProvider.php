<?php

namespace App\Providers;

use App\Support\ReadRouter;
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
        $this->app->scoped(ReadRouter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => app()->environment('testing') && config('performance.load_testing')
            ? Limit::none()
            : Limit::perMinute(60)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Sign-in endpoints: limited per account and IP so password guessing is
        // slowed down without locking out everyone behind a shared address.
        RateLimiter::for('auth', function (Request $request): Limit {
            $email = $request->input('email');
            $identity = is_string($email) ? mb_strtolower($email) : 'invalid-email';

            return Limit::perMinute(6)->by($identity.'|'.$request->ip());
        });
    }
}
