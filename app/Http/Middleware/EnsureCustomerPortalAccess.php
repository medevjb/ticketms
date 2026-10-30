<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerPortalAccess
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
            $user instanceof User && in_array('customer', $user->tyroRoleSlugs(), true),
            Response::HTTP_NOT_FOUND,
        );

        if (! $user->hasVerifiedEmail()) {
            return redirect('/email/verify');
        }

        return $next($request);
    }
}
