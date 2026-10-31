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

it('renders detailed analytics and the overview after reading serialized cached metrics', function (): void {
    config(['cache.default' => 'database']);
    $ticket = Ticket::factory()->create(['is_active' => true]);
    $disabledTicket = Ticket::factory()->create(['is_active' => false]);

    $this->actingAs($this->admin)->get(route('tyro-dashboard.index'))->assertOk();

    $this->get(route('dashboard.analytics.index'))
        ->assertOk()
        ->assertSee($ticket->number)
        ->assertSee($disabledTicket->number)
        ->assertSee('Disabled')
        ->assertViewHas('analytics', fn (array $data): bool => $data['recent_tickets']->contains(
            fn (Ticket $recent): bool => $recent->id === $ticket->id && $recent->is_active,
        ));

    $this->get(route('tyro-dashboard.index'))->assertOk()->assertSee($ticket->number);
});

it('paginates only active days and preserves period totals and date filters', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'Asia/Dhaka'));
    for ($day = 1; $day <= 12; $day++) {
        Ticket::factory()->create([
            'customer_id' => $this->customer->id, 'created_by' => $this->customer->id,
            'created_at' => CarbonImmutable::parse('2026-11-01 06:00:00', 'UTC')->addDays($day - 1),
        ]);
    }
    $filters = ['from' => '2026-11-01', 'to' => '2026-11-20', 'activity_per_page' => 10];

    $firstPage = $this->actingAs($this->admin)->get(route('dashboard.analytics.index', $filters))->assertOk();
    $paginator = $firstPage->viewData('dailyActivity');
    expect($paginator->total())->toBe(12);
    expect($paginator->count())->toBe(10);
    expect($paginator->items()[0]['date'])->toBe('2026-11-12');
    expect($paginator->nextPageUrl())->toContain('from=2026-11-01', 'to=2026-11-20', 'activity_page=2', '#daily-activity');

    $secondPage = $this->get(route('dashboard.analytics.index', [...$filters, 'activity_page' => 2]))->assertOk();
    expect(array_column($secondPage->viewData('dailyActivity')->items(), 'date'))->toBe(['2026-11-02', '2026-11-01']);
    expect($secondPage->viewData('activityTotals')['created'])->toBe(12);
    expect($secondPage->viewData('analytics')['metrics']['created'])->toBe(12);
    $this->get(route('dashboard.analytics.index', [...$filters, 'activity_page' => 999]))
        ->assertOk()->assertViewHas('dailyActivity', fn ($days): bool => $days->currentPage() === 2 && $days->count() === 2);
});

it('includes days with resolution or closure events even when no tickets were created', function (): void {
    $ticket = Ticket::factory()->closed()->create([
        'customer_id' => $this->customer->id, 'created_by' => $this->customer->id,
        'created_at' => CarbonImmutable::parse('2026-10-31 06:00:00', 'UTC'),
    ]);
    foreach (['ticket.resolve' => '2026-11-05', 'ticket.close' => '2026-11-06'] as $action => $date) {
        ActivityEntry::factory()->create([
            'ticket_id' => $ticket->id, 'subject_type' => Ticket::class, 'subject_id' => $ticket->id,
            'action' => $action, 'occurred_at' => CarbonImmutable::parse($date.' 06:00:00', 'UTC'),
        ]);
    }

    $response = $this->actingAs($this->admin)->get(route('dashboard.analytics.index', ['from' => '2026-11-01', 'to' => '2026-11-10']))->assertOk();

    expect(array_column($response->viewData('dailyActivity')->items(), 'date'))->toBe(['2026-11-06', '2026-11-05']);
    expect($response->viewData('activityTotals'))->toBe(['created' => 0, 'resolved' => 1, 'closed' => 1]);
});

it('shows a clear empty state without idle rows for a period with no activity', function (): void {
    $this->actingAs($this->admin)->get(route('dashboard.analytics.index', ['from' => '2026-11-01', 'to' => '2026-11-10']))
        ->assertOk()->assertSee('No activity in this date range')
        ->assertViewHas('dailyActivity', fn ($days): bool => $days->total() === 0 && $days->count() === 0);
});

it('validates daily activity pagination and ordering controls', function (): void {
    $this->actingAs($this->admin)->get(route('dashboard.analytics.index', ['activity_page' => 0, 'activity_per_page' => 500, 'activity_order' => 'invalid']))
        ->assertSessionHasErrors(['activity_page', 'activity_per_page', 'activity_order']);
});
