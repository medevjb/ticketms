<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalDashboardAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User && array_intersect(['admin', 'agent'], $user->tyroRoleSlugs()) !== [],
            Response::HTTP_NOT_FOUND,
        );

        return $next($request);
    }
}
