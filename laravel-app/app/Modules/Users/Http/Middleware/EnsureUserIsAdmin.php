<?php

namespace App\Modules\Users\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every non-administrator from the admin area, even when the request
 * is authenticated. Guests receive a 401 response.
 */
final class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! $user->isAdmin()) {
            abort(403, 'You do not have permission to access the admin area.');
        }

        return $next($request);
    }
}
