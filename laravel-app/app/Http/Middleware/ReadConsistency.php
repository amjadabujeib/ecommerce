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
        $connection = DB::connection();
        $connection->forgetRecordModificationState();
        $router->configure(function () use ($request): ?string {
            $actor = $request->user();

            return $actor ? app(UserDirectory::class)->readFence((int) $actor->getAuthIdentifier()) : null;
        });
        try {
            $response = $next($request);
            if (app()->environment('testing') && config('performance.diagnostics')) {
                $response->headers->set('X-Read-Database', $router->role());
            }

            return $response;
        } finally {
            try {
                // Database mutations, rather than the route/verb/status, determine this.
                if ($router->enabled() && $router->hasWrites() && ($actor = $request->user())) {
                    $lsn = DB::connection('primary_control')->selectOne('SELECT pg_current_wal_lsn()::text AS lsn')->lsn;
                    app(UserDirectory::class)->rememberWrite((int) $actor->getAuthIdentifier(), $lsn);
                }
            } finally {
                $router->finish();
                $connection->forgetRecordModificationState();
            }
        }
    }
}
