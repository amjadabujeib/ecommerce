<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class PerformanceGuardTest extends TestCase
{
    public function test_load_test_flag_cannot_disable_production_limits(): void
    {
        config(['performance.load_testing' => true]);
        $this->app->instance('env', 'production');
        $limit = (RateLimiter::limiter('api'))(Request::create('/api/v1/'));
        $this->assertNotInstanceOf(Unlimited::class, $limit);
        $this->assertSame(60, $limit->maxAttempts);
    }

    public function test_testing_bypass_keeps_login_limit_active(): void
    {
        config(['performance.load_testing' => true]);
        $request = Request::create('/api/v1/login', 'POST', ['email' => 'fixture@example.test']);
        $this->assertInstanceOf(Unlimited::class, (RateLimiter::limiter('api'))($request));
        $this->assertSame(6, (RateLimiter::limiter('auth'))($request)->maxAttempts);
    }
}
