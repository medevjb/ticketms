<?php

use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use HasinHayder\TyroDashboard\Support\DashboardRoute;

it('shows organization ticket metrics and removes demo navigation on the dashboard', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $ticket = Ticket::factory()->create(['subject' => '<script>alert("ticket")</script>']);

    $this->actingAs($admin)->get(route(DashboardRoute::name('index'), ['from' => '2026-11-03', 'to' => '2026-11-03']))
        ->assertOk()
        ->assertViewIs('admin.dashboard')
        ->assertViewHas('analytics', fn (array $data): bool => $data['metrics']['backlog'] === 1
            && $data['metrics']['created'] === 1 && $data['metrics']['unassigned'] === 1
            && $data['filters'] === ['from' => '2026-11-03', 'to' => '2026-11-03'])
        ->assertSee($ticket->number)
        ->assertSee($ticket->subject)
        ->assertDontSee($ticket->subject, false)
        ->assertSee('Ticket volume')
        ->assertSee('Agent workload')
        ->assertDontSee('Dashboard Components')
        ->assertDontSee('Widgets')
        ->assertDontSee('Media Library');
});

it('limits the agent dashboard to assigned tickets and hides organization breakdowns', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $assigned = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);
    $other = Ticket::factory()->create();

    $this->actingAs($agent)->get(route(DashboardRoute::name('index')))
        ->assertOk()
        ->assertSee($assigned->number)
        ->assertDontSee($other->number)
        ->assertDontSee('Agent workload')
        ->assertViewHas('analytics', fn (array $data): bool => $data['role'] === 'agent' && $data['metrics']['backlog'] === 1);
});

it('renders an empty dashboard without inventing SLA performance', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($admin)->get(route(DashboardRoute::name('index')))
        ->assertOk()
        ->assertSee('No ticket activity in this period.')
        ->assertSee('No tickets yet.')
        ->assertViewHas('analytics', fn (array $data): bool => $data['metrics']['sla_compliance_percent'] === null);
});

it('validates dashboard date ranges', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($admin)->get(route(DashboardRoute::name('index'), ['from' => '2025-01-01', 'to' => '2026-01-02']))
        ->assertSessionHasErrors(['to' => 'Choose a date range of 366 days or less.']);
});

it('rejects dashboard access for an account without an internal role', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route(DashboardRoute::name('index')))->assertNotFound();
});

it('redirects customers to their own portal', function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->get(route(DashboardRoute::name('index')))
        ->assertRedirectToRoute('customer.dashboard');
});

it('redirects guests to sign in', function (): void {
    $this->get(route(DashboardRoute::name('index')))->assertRedirect();
});
