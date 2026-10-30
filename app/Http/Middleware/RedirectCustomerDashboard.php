<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectCustomerDashboard
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/');
        $path = $request->path();
        $user = $request->user();

        if ($prefix !== '' && $user instanceof User && in_array('customer', $user->tyroRoleSlugs(), true)) {
            if ($path === $prefix) {
                return redirect()->route('customer.dashboard');
            }

            if (str_starts_with($path, $prefix.'/')) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        if ($prefix !== '' && $path === $prefix) {
            abort_unless(
                $user instanceof User && array_intersect(['admin', 'agent'], $user->tyroRoleSlugs()) !== [],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $next($request);
    }
}
