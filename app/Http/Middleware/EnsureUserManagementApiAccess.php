<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserManagementApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $token = $actor instanceof User ? $actor->currentAccessToken() : null;

        abort_unless(
            $actor instanceof User
                && $token instanceof PersonalAccessToken
                && $token->can('users.manage')
                && in_array('admin', $actor->tyroRoleSlugs(), true)
                && $actor->can('users.manage'),
            403,
        );

        return $next($request);
    }
}
