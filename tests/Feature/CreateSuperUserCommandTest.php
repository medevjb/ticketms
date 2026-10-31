<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('creates an admin through the standard Tyro command name', function (): void {
    $this->artisan('tyro-dashboard:createsuperuser')
        ->expectsQuestion('Name', 'Test Administrator')
        ->expectsQuestion('Email address', 'admin-command@example.test')
        ->expectsQuestion('Password', 'secure-test-password')
        ->expectsQuestion('Password (again)', 'secure-test-password')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin-command@example.test')->firstOrFail();

    expect($admin->email_verified_at)->not->toBeNull()
        ->and($admin->hasRole(Role::query()->where('name', 'admin')->firstOrFail()))->toBeTrue();
});

it('grants admin access to an existing account created without a role', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'existing-admin@example.test']);

    $this->artisan('tyro-dashboard:createsuperuser')
        ->expectsQuestion('Name', 'Existing Administrator')
        ->expectsQuestion('Email address', 'existing-admin@example.test')
        ->expectsConfirmation('This account already exists. Assign the admin role to it?', 'yes')
        ->assertSuccessful();

    $user->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole(Role::query()->where('name', 'admin')->firstOrFail()))->toBeTrue();
});

it('keeps the previous app command name as an alias', function (): void {
    $user = User::factory()->create(['email' => 'legacy-command@example.test']);

    $this->artisan('ticketing:make-admin')
        ->expectsQuestion('Name', 'Existing Administrator')
        ->expectsQuestion('Email address', 'legacy-command@example.test')
        ->expectsConfirmation('This account already exists. Assign the admin role to it?', 'yes')
        ->assertSuccessful();

    expect($user->fresh()->hasRole(Role::query()->where('name', 'admin')->firstOrFail()))->toBeTrue();
});

it('stops cleanly when the application admin role has not been seeded', function (): void {
    Role::query()->where('name', 'admin')->firstOrFail()->delete();

    $this->artisan('tyro-dashboard:createsuperuser')
        ->expectsQuestion('Name', 'Unconfigured Administrator')
        ->expectsQuestion('Email address', 'unconfigured@example.test')
        ->expectsOutputToContain('Run the application seeders first')
        ->assertFailed();

    expect(User::query()->where('email', 'unconfigured@example.test')->exists())->toBeFalse();
});
