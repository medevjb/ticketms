<?php

use App\Models\AgentProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Database\Seeders\TicketingTestAccountsSeeder;

it('seeds one local test customer and one test agent idempotently', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->seed(TicketingTestAccountsSeeder::class);
    $this->seed(TicketingTestAccountsSeeder::class);

    $customer = User::query()->where('email', 'user@user.com')->firstOrFail();
    $agent = User::query()->where('email', 'agent@agent.com')->firstOrFail();
    $customerRole = Role::query()->where('name', 'customer')->firstOrFail();
    $agentRole = Role::query()->where('name', 'agent')->firstOrFail();

    expect(User::query()->count())->toBe(2)
        ->and($customer->email_verified_at)->not->toBeNull()
        ->and($customer->hasRole($customerRole))->toBeTrue()
        ->and($agent->hasRole($agentRole))->toBeTrue()
        ->and(AgentProfile::query()->where('user_id', $agent->getKey())->firstOrFail()->auto_assignment_enabled)->toBeTrue();
});
