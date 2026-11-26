<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the serving container identifier when APP_INSTANCE is configured,
 * allowing load-balancing and failover verification across replicas.
 */
final class InstanceIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $instance = config('app.instance');

        if (is_string($instance) && $instance !== '') {
            $response->headers->set('X-Served-By', $instance);
        }

        return $response;
    }
}