<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the public support landing page with the published SLA schedule', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);

    $response = $this->get(route('home'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('slaOverview.timezone', 'Asia/Dhaka')
        ->has('slaOverview.windows', 5)
        ->has('slaOverview.targets', 4)
        ->where('slaOverview.targets.0.duration', '2 business hours'));
});

test('shows the separate customer dashboard and redirects the generic dashboard to it', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee('Your support space')
        ->assertSee('My requests')
        ->assertDontSee('Administration');

    $this->get('/dashboard')->assertRedirect(route('customer.dashboard'));
});

test('shows the guided ticket form and current business schedule in the customer portal', function () {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route('customer.tickets.create'))
        ->assertOk()
        ->assertSee('Tell us what you need')
        ->assertSee('Priority and helpful files')
        ->assertSee('Sunday 9:00 AM–6:00 PM')
        ->assertSee('Asia/Dhaka')
        ->assertSee('Submit ticket');
});
