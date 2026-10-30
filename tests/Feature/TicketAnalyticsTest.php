<?php

use App\Enums\TicketTransition;
use App\Models\ActivityEntry;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Analytics\TicketAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->customer = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $this->agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $this->customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $this->otherCustomer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
});

it('keeps customer and agent analytics within their own ticket scopes', function (): void {
    $ownTicket = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'created_by' => $this->customer->id,
        'created_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
    ]);
    $otherTicket = Ticket::factory()->create([
        'customer_id' => $this->otherCustomer->id,
        'created_by' => $this->otherCustomer->id,
        'assigned_agent_id' => $this->agent->id,
        'created_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
    ]);
    Ticket::factory()->create([
        'customer_id' => $this->otherCustomer->id,
        'created_by' => $this->otherCustomer->id,
        'created_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
    ]);

    $this->actingAs($this->customer)
        ->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee($ownTicket->number)
        ->assertDontSee($otherTicket->number)
        ->assertSee('All requests')
        ->assertSee('In progress');

    $this->actingAs($this->agent)
        ->get(route('dashboard.analytics.index', ['from' => '2026-11-02', 'to' => '2026-11-02']))
        ->assertOk()
        ->assertSee($otherTicket->number)
        ->assertDontSee($ownTicket->number);

    $agentData = app(TicketAnalyticsService::class)->dashboard($this->agent->fresh(), '2026-11-02', '2026-11-02');
    expect($agentData['metrics']['created'])->toBe(1)
        ->and($agentData['metrics']['queue_preview'])->toBe(2);
});

it('uses Dhaka date boundaries and includes disabled live tickets in operational backlog', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
    $beforeDhakaMidnight = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'created_by' => $this->customer->id,
        'is_active' => false,
        'sla_warning_at' => CarbonImmutable::parse('2026-11-02 05:00:00', 'UTC'),
        'sla_due_at' => CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'),
        'created_at' => CarbonImmutable::parse('2026-11-01 17:59:59', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 17:59:59', 'UTC'),
    ]);
    $onDhakaDate = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'created_by' => $this->customer->id,
        'created_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'),
    ]);
    Ticket::factory()->waiting()->create([
        'customer_id' => $this->otherCustomer->id,
        'created_by' => $this->otherCustomer->id,
    ]);
    $data = app(TicketAnalyticsService::class)->dashboard($this->admin, '2026-11-02', '2026-11-02');

    expect($data['metrics']['created'])->toBe(1)
        ->and($data['daily'][0]['created'])->toBe(1)
        ->and($data['metrics']['backlog'])->toBe(3)
        ->and($data['metrics']['paused'])->toBe(1)
        ->and($data['metrics']['approaching'])->toBe(0)
        ->and($data['metrics']['breached'])->toBe(1);
    $this->assertDatabaseHas('tickets', ['id' => $onDhakaDate->id, 'is_active' => true]);
});

it('uses resolution-time snapshots for SLA compliance and duration denominators', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
    $resolvedTicket = Ticket::factory()->resolved()->create([
        'customer_id' => $this->customer->id,
        'created_by' => $this->customer->id,
        'assigned_agent_id' => $this->agent->id,
    ]);
    $secondResolvedTicket = Ticket::factory()->resolved()->create([
        'customer_id' => $this->otherCustomer->id,
        'created_by' => $this->otherCustomer->id,
        'assigned_agent_id' => $this->agent->id,
    ]);
    foreach ([[$resolvedTicket, 3600, 7200], [$secondResolvedTicket, 9000, 7200]] as [$ticket, $consumed, $target]) {
        ActivityEntry::factory()->create([
            'ticket_id' => $ticket->id,
            'subject_type' => Ticket::class,
            'subject_id' => $ticket->id,
            'action' => 'ticket.resolve',
            'occurred_at' => CarbonImmutable::parse('2026-11-03 04:00:00', 'UTC'),
            'changes' => [
                'sla_consumed_seconds' => $consumed,
                'sla_target_seconds' => $target,
                'assigned_agent_id' => $this->agent->id,
            ],
        ]);
    }

    $data = app(TicketAnalyticsService::class)->dashboard($this->admin, '2026-11-03', '2026-11-03');

    expect($data['metrics']['resolved'])->toBe(2)
        ->and($data['metrics']['resolution_cycles'])->toBe(2)
        ->and($data['metrics']['resolution_samples'])->toBe(2)
        ->and($data['metrics']['sla_met_cycles'])->toBe(1)
        ->and($data['metrics']['sla_compliance_percent'])->toBe(50.0)
        ->and($data['metrics']['average_resolution_seconds'])->toBe(6300.0)
        ->and($data['metrics']['median_resolution_seconds'])->toBe(6300.0);
});

it('records immutable SLA inputs in the resolution activity snapshot', function (): void {
    $ticket = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'created_by' => $this->customer->id,
        'assigned_agent_id' => $this->agent->id,
    ]);

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Start->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
    ])->assertRedirect();

    $startedTicket = $ticket->fresh();
    $this->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Resolve->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $startedTicket->lock_version,
        'public_message' => 'The issue has been resolved and confirmed.',
    ])->assertRedirect();

    $activity = ActivityEntry::query()->where('ticket_id', $ticket->id)->where('action', 'ticket.resolve')->firstOrFail();
    expect($activity->changes)->toHaveKeys(['resolution_cycle', 'sla_target_seconds', 'sla_consumed_seconds', 'sla_met', 'assigned_agent_id'])
        ->and($activity->changes['sla_target_seconds'])->toBe($ticket->sla_target_seconds)
        ->and($activity->changes['assigned_agent_id'])->toBe($this->agent->id);
});

it('rejects analytics ranges longer than the documented limit and roles without analytics access', function (): void {
    $this->actingAs($this->admin)->get(route('dashboard.analytics.index', ['from' => '2025-01-01', 'to' => '2026-01-02']))
        ->assertSessionHasErrors('to');

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('dashboard.analytics.index'))->assertNotFound();
});
