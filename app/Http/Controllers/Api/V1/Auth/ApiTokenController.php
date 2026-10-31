<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueApiTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function store(IssueApiTokenRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)
            || ! $user->is_active || ! in_array('admin', $user->tyroRoleSlugs(), true)
            || ! $user->can('users.manage')) {
            return response()->json(['message' => 'The supplied credentials are invalid.'], 401);
        }

        $expiresAt = now()->addHours(8);
        $token = $user->createToken($credentials['token_name'] ?? 'User Management API', ['users.manage'], $expiresAt);

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);
        $token->delete();

        return response()->json(['message' => 'API token revoked.']);
    }
}
