<?php

use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('pages and sorts ticket rows on the server with unassigned and priority indicators', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $ticket = Ticket::factory()->create(['subject' => '<script>alert("ticket")</script>']);

    $response = $this->actingAs($admin)->getJson(route('dashboard.tickets.data', ['draw' => 1, 'start' => 0, 'length' => 15]));

    $response->assertOk()->assertJsonPath('draw', 1)->assertJsonPath('recordsTotal', 1)->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.DT_RowClass', 'ticket-unassigned');
    expect($response->json('data.0.assignee'))->toContain('Unassigned');
    expect($response->json('data.0.priority'))->toContain('ticket-priority-');
    expect($response->json('data.0.subject'))->toContain(e($ticket->subject))->not->toContain($ticket->subject);
    expect($response->json('data.0'))->not->toHaveKeys(['description', 'password', 'customer_id']);
});

it('filters unassigned tickets and applies pagination across sorted results', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    Ticket::factory()->create(['number' => 'TKT-0001']);
    $second = Ticket::factory()->create(['number' => 'TKT-0002']);
    Ticket::factory()->create(['assigned_agent_id' => $admin->id, 'number' => 'TKT-0003']);

    $response = $this->actingAs($admin)->getJson(route('dashboard.tickets.data', [
        'draw' => 2, 'start' => 1, 'length' => 15, 'assignment' => 'unassigned', 'sort' => 'number', 'direction' => 'asc',
    ]));

    $response->assertOk()->assertJsonPath('recordsFiltered', 2)->assertJsonCount(1, 'data');
    expect($response->json('data.0.ticket'))->toContain($second->number);
});

it('keeps the datatable scoped to the current agent and hides customer and assignee columns', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $own = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);
    $other = Ticket::factory()->create();

    $response = $this->actingAs($agent)->getJson(route('dashboard.tickets.data', ['draw' => 1, 'start' => 0, 'length' => 15]));

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.ticket'))->toContain($own->number)->not->toContain($other->number);
    expect($response->json('data.0'))->not->toHaveKeys(['customer', 'assignee', 'selection']);
});

it('rejects unlimited page sizes and invalid sort columns', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($admin)->getJson(route('dashboard.tickets.data', ['draw' => 1, 'start' => 0, 'length' => -1, 'sort' => 'password']))
        ->assertUnprocessable()->assertJsonValidationErrors(['length', 'sort']);
});

it('keeps customers out of the staff datatable', function (): void {
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($customer)->getJson(route('dashboard.tickets.data', ['draw' => 1, 'start' => 0, 'length' => 15]))->assertNotFound();
});

it('ignores unvalidated protocol columns instead of querying client-supplied column names', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    Ticket::factory()->create();

    $this->actingAs($admin)->getJson(route('dashboard.tickets.data', [
        'draw' => 1, 'start' => 0, 'length' => 15,
        'columns' => [['data' => 'password', 'name' => 'password', 'searchable' => 'true', 'search' => ['value' => 'forged']]],
        'order' => [['column' => 0, 'dir' => 'asc']],
    ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('error');
});
