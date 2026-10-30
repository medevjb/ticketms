<?php

namespace App\Http\Controllers;

use App\Http\Requests\Agents\CompleteAgentInvitationRequest;
use App\Services\Agents\AgentManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentInvitationController extends Controller
{
    public function show(string $token, AgentManagementService $agents): View
    {
        $profile = $agents->invitationProfile($token);

        if ($profile === null) {
            return view('agents.accept-invitation', [
                'layout' => config('tyro-login.layout', 'centered'),
                'branding' => config('tyro-login.branding', []),
                'backgroundImage' => config('tyro-login.background_image'),
                'videoBackground' => config('tyro-login.video_background'),
                'pageContent' => [
                    'title' => 'Invitation unavailable',
                    'subtitle' => 'This link may have expired or already been used.',
                ],
                'invitationUnavailable' => true,
            ]);
        }

        return view('agents.accept-invitation', [
            'layout' => config('tyro-login.layout', 'centered'),
            'branding' => config('tyro-login.branding', []),
            'backgroundImage' => config('tyro-login.background_image'),
            'videoBackground' => config('tyro-login.video_background'),
            'pageContent' => [
                'title' => 'Set your agent password',
                'subtitle' => 'Choose a password to activate your support account.',
            ],
            'email' => $profile->user->email,
            'token' => $token,
            'invitationUnavailable' => false,
        ]);
    }

    public function store(string $token, CompleteAgentInvitationRequest $request, AgentManagementService $agents): RedirectResponse
    {
        if (! hash_equals($token, (string) $request->validated('token'))) {
            return to_route('tyro-login.login')->with('error', 'This invitation link is invalid. Request a new invitation from your administrator.');
        }

        try {
            $agents->completeInvitation($token, (string) $request->validated('password'));
        } catch (ValidationException $exception) {
            $tokenErrors = $exception->errors()['token'] ?? null;

            if ($tokenErrors !== null) {
                return to_route('tyro-login.login')->with('error', $tokenErrors[0]);
            }

            throw $exception;
        }

        return to_route('tyro-login.login')->with('success', 'Your password is set and your agent account is active. Sign in to continue.');
    }
}
