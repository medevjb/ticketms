<?php

use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('lets administrators create customers and edit their contact details', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($admin)->post(route('dashboard.users.store'), [
        'name' => 'New Customer', 'email' => 'NEW@example.com',
        'password' => 'CustomerPassword123!', 'password_confirmation' => 'CustomerPassword123!',
        'role' => 'admin', 'is_active' => false,
    ])->assertRedirect();

    $customer = User::query()->where('email', 'new@example.com')->firstOrFail();
    expect($customer->tyroRoleSlugs())->toBe(['customer']);
    expect($customer->is_active)->toBeTrue();
    expect(Hash::check('CustomerPassword123!', $customer->password))->toBeTrue();
    expect($customer->email_verified_at)->toBeNull();

    $this->put(route('dashboard.users.update', $customer), ['name' => 'Updated Customer', 'email' => 'updated@example.com'])->assertRedirectToRoute('dashboard.users.show', $customer);
    $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Updated Customer', 'email' => 'updated@example.com']);
    $this->get(route('dashboard.users.index'))->assertOk()->assertSee('Updated Customer');
    $this->get(route('dashboard.users.edit', $customer))->assertOk();
    $this->get(route('dashboard.users.create'))->assertOk();
});

it('shows agents customer profiles while protecting staff and unassigned ticket details', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $assigned = Ticket::factory()->create(['customer_id' => $customer->id, 'assigned_agent_id' => $agent->id]);
    $unassigned = Ticket::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($agent)->get(route('dashboard.users.index'))->assertOk()->assertSee($customer->email)->assertDontSee($agent->email)->assertDontSee('Add customer');
    $this->get(route('dashboard.users.show', $customer))->assertOk()->assertSee($assigned->number)->assertDontSee($unassigned->number);
    $this->get(route('dashboard.users.show', $agent))->assertNotFound();
    $this->get(route('dashboard.users.edit', $customer))->assertRedirectToRoute('tyro-dashboard.index');
    $this->post(route('dashboard.users.store'), [])->assertRedirectToRoute('tyro-dashboard.index');
    $this->put(route('dashboard.users.update', $customer), ['name' => 'Changed', 'email' => 'changed@example.com'])->assertRedirectToRoute('tyro-dashboard.index');
    $this->assertDatabaseHas('users', ['id' => $customer->id, 'email' => $customer->email]);
});

it('validates unique emails and password confirmation when creating customers', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($admin)->post(route('dashboard.users.store'), ['name' => 'Invalid', 'email' => $admin->email, 'password' => 'short', 'password_confirmation' => 'different'])
        ->assertSessionHasErrors(['email', 'password']);
    $this->assertDatabaseCount('users', 1);
});

it('clears email verification when an administrator changes a verified email', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer = User::factory()->create();

    $this->actingAs($admin)->put(route('dashboard.users.update', $customer), ['name' => $customer->name, 'email' => 'replacement@example.com'])->assertRedirect();

    expect($customer->fresh()->email_verified_at)->toBeNull();
});

it('blocks customers from the internal users module', function (): void {
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route('dashboard.users.index'))->assertNotFound();
    $this->post(route('dashboard.users.store'), [])->assertNotFound();
});

it('consolidates account lifecycle controls and archived filters in the users module', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($admin)->get(route('dashboard.users.index'))
        ->assertOk()->assertSee('Selected account action')->assertDontSee('User access');
    $this->post(route('dashboard.users.bulk'), ['action' => 'archive', 'selected_ids' => [$customer->id]])
        ->assertRedirectToRoute('dashboard.users.index');
    $this->assertSoftDeleted($customer);
    $this->get(route('dashboard.users.index', ['status' => 'archived']))
        ->assertOk()->assertSee($customer->email)->assertSee('Select to restore');
    $this->post(route('dashboard.users.bulk'), ['action' => 'restore', 'selected_ids' => [$customer->id]])
        ->assertRedirectToRoute('dashboard.users.index');
    $this->assertNotSoftDeleted($customer);
    expect($customer->fresh()->is_active)->toBeFalse();
});
