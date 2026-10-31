<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

function userManagementApiHeaders(User $user, array $abilities = ['users.manage']): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('feature-test', $abilities)->plainTextToken];
}

it('issues short-lived tokens only to active administrators who can manage users', function (): void {
    $admin = User::factory()->create(['password' => 'AdminPassword123!']);
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer = User::factory()->create(['password' => 'AdminPassword123!']);
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $admin->email,
        'password' => 'AdminPassword123!',
        'token_name' => 'Postman',
    ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');

    $token = $response->json('data.access_token');
    expect($token)->toBeString()->not->toBeEmpty();
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $admin->id,
        'name' => 'Postman',
        'abilities' => '["users.manage"]',
    ]);

    $this->postJson('/api/v1/auth/token', ['email' => $customer->email, 'password' => 'AdminPassword123!'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => 'incorrect'])->assertUnauthorized();
});

it('protects user endpoints with bearer authentication, token ability, role, and permission', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    expect($admin->can('users.manage'))->toBeTrue()
        ->and($admin->tyroRoleSlugs())->toContain('admin');

    $this->getJson('/api/v1/users')->assertUnauthorized();
    $this->withHeaders(userManagementApiHeaders($admin, ['profile:read']))->getJson('/api/v1/users')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withHeaders(userManagementApiHeaders($agent))->getJson('/api/v1/users')->assertForbidden();
    app('auth')->forgetGuards();
    $token = $admin->createToken('admin-test', ['users.manage']);
    expect($token->accessToken->can('users.manage'))->toBeTrue();
    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])->getJson('/api/v1/users')->assertOk();
});

it('creates, searches, updates, disables, archives, and restores customer accounts through the API', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $headers = userManagementApiHeaders($admin);

    $created = $this->withHeaders($headers)->postJson('/api/v1/users', [
        'name' => 'API Customer',
        'email' => 'API-Customer@example.test',
        'password' => 'CustomerPassword123!',
        'password_confirmation' => 'CustomerPassword123!',
        'role' => 'admin',
        'is_active' => false,
    ])->assertCreated()->assertJsonPath('data.name', 'API Customer')
        ->assertJsonPath('data.roles.0', 'customer')
        ->assertJsonPath('data.is_active', true);
    $userId = (int) $created->json('data.id');
    $customer = User::query()->findOrFail($userId);
    expect($customer->email)->toBe('api-customer@example.test')
        ->and($customer->created_by)->toBe($admin->id)
        ->and($customer->email_verified_at)->toBeNull();

    $this->withHeaders($headers)->getJson('/api/v1/users?search=API%20Customer&per_page=10')
        ->assertOk()->assertJsonPath('data.0.id', $userId)->assertJsonPath('meta.per_page', 10);
    $this->withHeaders($headers)->getJson('/api/v1/users/'.$userId)
        ->assertOk()->assertJsonPath('data.ticket_count', 0)->assertJsonPath('data.active_ticket_count', 0);

    $this->withHeaders($headers)->patchJson('/api/v1/users/'.$userId, [
        'name' => 'Updated API Customer',
        'email' => 'updated-api-customer@example.test',
    ])->assertOk()->assertJsonPath('data.name', 'Updated API Customer');
    expect($customer->fresh()->email_verified_at)->toBeNull();

    $this->withHeaders($headers)->patchJson('/api/v1/users/'.$userId.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->withHeaders($headers)->deleteJson('/api/v1/users/'.$userId)
        ->assertOk()->assertJsonPath('message', 'User archived.');
    $this->assertSoftDeleted($customer);
    $this->withHeaders($headers)->postJson('/api/v1/users/'.$userId.'/restore')
        ->assertOk()->assertJsonPath('data.is_archived', false)->assertJsonPath('data.is_active', false);
    $this->assertNotSoftDeleted($customer);
});

it('returns validation errors and ignores unsupported privilege fields in user writes', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $headers = userManagementApiHeaders($admin);

    $this->withHeaders($headers)->postJson('/api/v1/users', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
    $this->withHeaders($headers)->getJson('/api/v1/users?per_page=1000')->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('revokes the current bearer token and rejects it on the next request', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $headers = userManagementApiHeaders($admin);

    $this->withHeaders($headers)->deleteJson('/api/v1/auth/token')->assertOk();
    app('auth')->forgetGuards();
    $this->withHeaders($headers)->getJson('/api/v1/users')->assertUnauthorized();
});
