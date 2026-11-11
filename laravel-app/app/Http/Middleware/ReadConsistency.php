<?php

namespace App\Http\Middleware;

use App\Modules\Users\PublicApi\UserDirectory;
use App\Support\ReadRouter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ReadConsistency
{
    public function handle(Request $request, Closure $next): Response
    {
        $router = app(ReadRouter::class);
        $router->configure(function () use ($request): ?string {
            $actor = $request->user();

            return $actor ? app(UserDirectory::class)->readFence((int) $actor->getAuthIdentifier()) : null;
        });
        $response = $next($request);
        if (config('performance.replica') && config('database.default') === 'pgsql' && $request->is('api/*')) {
            if (! $request->isMethodSafe() && ! $request->is('api/v1/login', 'api/v1/logout', 'api/v1/refresh') && $response->isSuccessful() && ($actor = $request->user())) {
                // Persist after the business commit, before acknowledging success.
                // This survives Redis eviction/outages and separate HTTP requests.
                $lsn = DB::selectOne('SELECT pg_current_wal_lsn()::text AS lsn')->lsn;
                app(UserDirectory::class)->rememberWrite((int) $actor->getAuthIdentifier(), $lsn);
            }
            if (app()->environment('testing') && config('performance.diagnostics')) {
                $response->headers->set('X-Read-Database', $router->role());
            }
        }

        return $response;
    }
}
