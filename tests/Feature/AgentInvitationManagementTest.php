<?php

use App\Mail\AgentInvitationMail;
use App\Models\Role;
use App\Models\User;
use App\Services\Agents\AgentManagementService;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('resends an expired invitation for an inactive agent and accepts the replacement', function (): void {
    Mail::fake();
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $service = app(AgentManagementService::class);
    $invitation = $service->createInvitedAgent($admin, 'Pending Agent', 'pending@example.com', true);
    $invitation['profile']->forceFill(['invitation_expires_at' => now()->subMinute()])->save();

    $this->actingAs($admin)->from(route('dashboard.agents.index'))
        ->post(route('dashboard.agents.invitation.resend', $invitation['user']))
        ->assertRedirectToRoute('dashboard.agents.index')->assertSessionHasNoErrors();

    $token = null;
    Mail::assertSent(AgentInvitationMail::class, function (AgentInvitationMail $mail) use (&$token): bool {
        $token = basename(parse_url($mail->acceptUrl, PHP_URL_PATH));

        return $mail->hasTo('pending@example.com');
    });
    expect($service->invitationProfile($invitation['token']))->toBeNull();
    expect($service->invitationProfile($token))->not->toBeNull();
    $service->completeInvitation($token, 'NewAgentPassword123!');
    expect($invitation['user']->fresh()->is_active)->toBeTrue();
    expect(Hash::check('NewAgentPassword123!', $invitation['user']->fresh()->password))->toBeTrue();
    expect($invitation['profile']->fresh()->invitation_accepted_at)->not->toBeNull();
});

it('rejects resending an already accepted invitation', function (): void {
    Mail::fake();
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $service = app(AgentManagementService::class);
    $invitation = $service->createInvitedAgent($admin, 'Accepted Agent', 'accepted@example.com', false);
    $service->completeInvitation($invitation['token'], 'NewAgentPassword123!');

    $this->actingAs($admin)->post(route('dashboard.agents.invitation.resend', $invitation['user']))
        ->assertSessionHasErrors(['agent' => 'This agent does not have a pending invitation.']);

    Mail::assertNothingSent();
});
