<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

it('shows validation and flash messages once in toasts without page banners', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $this->actingAs($admin)->withSession([
        'success' => 'Category saved successfully.',
        'bulk_failures' => [['id' => 42, 'message' => 'This record cannot be archived.']],
    ])->post(route('dashboard.categories.store'), ['name' => '', 'slug' => 'toast-test'])->assertRedirect();

    $response = $this->get(route('dashboard.categories.index', ['create' => 1]));

    $response->assertOk()->assertSee('toast toast-success')->assertSee('toast toast-error')->assertSee('toast toast-warning')
        ->assertDontSee('class="alert ', false)->assertDontSee('<p id="name-error"', false);
    expect(substr_count($response->getContent(), 'Category saved successfully.'))->toBe(1);
    expect(substr_count($response->getContent(), 'The name field is required.'))->toBe(1);
    expect(substr_count($response->getContent(), 'This record cannot be archived.'))->toBe(1);
});

it('moves bulk failures from the main pane into a single escaped toast', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $message = '<script>alert("bulk")</script>';

    $response = $this->actingAs($admin)->withSession([
        'bulk_failures' => [['id' => 42, 'message' => $message]],
    ])->get(route('dashboard.categories.index'));

    $response->assertOk()->assertSee($message)->assertDontSee($message, false)->assertDontSee('class="alert ', false);
    expect(substr_count($response->getContent(), e($message)))->toBe(1);
});

it('shows exact customer validation errors in the portal toast without inline messages', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $this->actingAs($customer)->put(route('customer.profile.update'), ['name' => ''])->assertRedirect();

    $response = $this->actingAs($customer)->get(route('customer.profile'));

    $response->assertOk()->assertSee('customer-toast-error')->assertDontSee('<p class="customer-field-error"', false);
    expect(substr_count($response->getContent(), 'The name field is required.'))->toBe(1);
});
