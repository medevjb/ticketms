<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockTyroNativeAuthorizationRoutes
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/');

        if ($prefix !== '' && $request->is(
            "{$prefix}/users",
            "{$prefix}/users/*",
            "{$prefix}/roles",
            "{$prefix}/roles/*",
            "{$prefix}/privileges",
            "{$prefix}/privileges/*",
        )) {
            return response('', Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
