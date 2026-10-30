<?php

use App\Enums\TicketStatus;
use App\Enums\TicketTransition;
use App\Models\ActivityEntry;
use App\Models\CalendarHoliday;
use App\Models\CalendarVersion;
use App\Models\CalendarWindow;
use App\Models\Category;
use App\Models\NotificationDelivery;
use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\TicketingSetting;
use App\Models\TicketSlaInterval;
use App\Models\User;
use App\Services\Tickets\SlaService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Facades\DB;
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

function ticketForWorkflow(User $customer, ?User $agent = null, string $prioritySlug = 'high'): Ticket
{
    $settings = TicketingSetting::query()->findOrFail(1);
    $policy = SlaPolicy::query()->whereKey($settings->current_sla_policy_id)->firstOrFail();
    $priority = Priority::query()->where('slug', $prioritySlug)->firstOrFail();
    $target = SlaPolicyTarget::query()->where('sla_policy_id', $policy->getKey())->where('priority_id', $priority->getKey())->firstOrFail();
    $calendar = CalendarVersion::query()->whereKey($policy->calendar_version_id)->firstOrFail();
    $startedAt = CarbonImmutable::now('UTC')->startOfSecond();
    $projection = app(SlaService::class)->creationProjection($calendar, $startedAt, (int) $target->target_seconds, (int) $policy->warning_percent);

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'created_by' => $customer->getKey(),
        'assigned_agent_id' => $agent?->getKey(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => $priority->getKey(),
        'sla_policy_id' => $policy->getKey(),
        'calendar_version_id' => $calendar->getKey(),
        'sla_target_seconds' => $target->target_seconds,
        'sla_started_at' => $startedAt,
        'sla_warning_at' => $projection['warning_at'],
        'sla_due_at' => $projection['due_at'],
    ]);

    return $ticket->refresh();
}

it('creates tickets with Dhaka-year numbering, pinned SLA data, audit and durable delivery intents', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka'));
    $category = Category::query()->active()->firstOrFail();
    $critical = Priority::query()->where('slug', 'critical')->firstOrFail();
    $operationKey = (string) Str::uuid();

    $this->actingAs($this->customer)->post(route('customer.tickets.store'), [
        'operation_key' => $operationKey,
        'category_id' => $category->id,
        'priority_id' => $critical->id,
        'subject' => 'Cannot access the billing portal',
        'description' => 'The billing portal returns an error after sign in.',
    ])->assertRedirect();

    $ticket = Ticket::query()->firstOrFail();
    expect($ticket->number)->toBe('TKT-2026-000001')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->customer_id)->toBe($this->customer->id)
        ->and($ticket->created_by)->toBe($this->customer->id)
        ->and($ticket->assigned_agent_id)->toBeNull()
        ->and($ticket->sla_target_seconds)->toBe(7200)
        ->and($ticket->sla_due_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 10:00')
        ->and($ticket->sla_warning_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 09:36');

    $this->assertDatabaseHas('activity_entries', [
        'ticket_id' => $ticket->id,
        'actor_id' => $this->customer->id,
        'action' => 'ticket.created',
        'operation_key' => $operationKey,
    ]);
    expect(NotificationDelivery::query()->whereHas('activity', fn ($query) => $query->where('ticket_id', $ticket->id))->count())->toBe(4)
        ->and(DB::table('ticket_sequences')->where('year', 2026)->value('last_number'))->toBe(1);
});

it('rate limits ticket creation per authenticated actor', function (): void {
    $this->actingAs($this->customer);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post(route('customer.tickets.store'), [])->assertRedirect();
    }

    $this->post(route('customer.tickets.store'), [])->assertStatus(429);
});

it('reuses an identical create operation and rejects reuse with a changed payload', function (): void {
    $category = Category::query()->active()->firstOrFail();
    $priority = Priority::query()->where('slug', 'medium')->firstOrFail();
    $operationKey = (string) Str::uuid();
    $payload = [
        'operation_key' => $operationKey,
        'category_id' => $category->id,
        'priority_id' => $priority->id,
        'subject' => 'A repeated submission',
        'description' => 'The same operation should return the existing ticket.',
    ];

    $this->actingAs($this->customer)->post(route('customer.tickets.store'), $payload)->assertRedirect();
    $this->post(route('customer.tickets.store'), $payload)->assertRedirect();

    expect(Ticket::query()->count())->toBe(1)
        ->and(DB::table('ticket_sequences')->where('year', now('Asia/Dhaka')->year)->value('last_number'))->toBe(1);

    $payload['subject'] = 'A different payload';
    $this->post(route('customer.tickets.store'), $payload)->assertStatus(409);
    expect(Ticket::query()->count())->toBe(1);
});

it('allows only verified customers and administrators to create customer-owned tickets', function (): void {
    $category = Category::query()->active()->firstOrFail();
    $priority = Priority::query()->where('slug', 'low')->firstOrFail();
    $payload = [
        'operation_key' => (string) Str::uuid(),
        'category_id' => $category->id,
        'priority_id' => $priority->id,
        'subject' => 'A ticket',
        'description' => 'Ticket body.',
    ];

    $this->actingAs($this->agent)->post(route('dashboard.tickets.store'), $payload)->assertForbidden();
    $this->actingAs($this->customer)->post(route('customer.tickets.store'), $payload + ['customer_id' => $this->otherCustomer->id])->assertSessionHasErrors('customer_id');
    $unverified = $this->customer->fresh()->forceFill(['email_verified_at' => null]);
    $unverified->save();
    $this->actingAs($unverified)
        ->post(route('customer.tickets.store'), $payload)
        ->assertRedirect('/email/verify');

    $this->actingAs($this->admin)->post(route('dashboard.tickets.store'), $payload + ['customer_id' => $this->otherCustomer->id])->assertRedirect();
    expect(Ticket::query()->firstOrFail()->customer_id)->toBe($this->otherCustomer->id);
});

it('keeps customer and agent worklists scoped and exposes only a safe unassigned preview', function (): void {
    $own = ticketForWorkflow($this->customer);
    $other = ticketForWorkflow($this->otherCustomer);
    $assigned = ticketForWorkflow($this->customer, $this->agent);
    $unassigned = ticketForWorkflow($this->otherCustomer);
    $unassigned->forceFill(['description' => 'Private technical details'])->save();

    $this->actingAs($this->customer)->get(route('customer.tickets.index'))
        ->assertOk()->assertSee($own->number)->assertDontSee($other->number);
    $this->get(route('customer.tickets.show', $other->id))->assertNotFound();

    $this->actingAs($this->agent)->get(route('dashboard.tickets.index'))
        ->assertOk()->assertSee($assigned->number)->assertSee($unassigned->number)->assertDontSee('Private technical details');
    $this->get(route('dashboard.tickets.show', $unassigned->id))->assertNotFound();
    $this->get(route('dashboard.tickets.show', $assigned->id))->assertOk();
});

it('hides another customer ticket from search and rejects cross-owner edits without writes', function (): void {
    $other = ticketForWorkflow($this->otherCustomer);
    $this->actingAs($this->customer)->get(route('customer.tickets.index', ['search' => $other->number]))
        ->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === 0);

    $this->put(route('customer.tickets.update', $other->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $other->lock_version,
        'subject' => 'Unauthorized subject change',
        'description' => 'Unauthorized description change.',
    ])->assertNotFound();

    expect($other->fresh()->subject)->not->toBe('Unauthorized subject change')
        ->and(ActivityEntry::query()->where('ticket_id', $other->getKey())->where('action', 'ticket.updated')->exists())->toBeFalse();
});

it('updates customer content only before a public staff response and rejects stale versions', function (): void {
    $ticket = ticketForWorkflow($this->customer, $this->agent);

    $this->actingAs($this->customer)->get(route('customer.tickets.edit', $ticket->id))->assertOk();
    $this->put(route('customer.tickets.update', $ticket->id), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'subject' => 'Updated subject',
        'description' => 'Updated description with clear reproduction steps.',
    ])->assertRedirect();

    expect($ticket->fresh()->subject)->toBe('Updated subject')->and($ticket->fresh()->lock_version)->toBe(2);
    $this->put(route('customer.tickets.update', $ticket->id), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 1,
        'subject' => 'Stale subject',
        'description' => 'Stale description.',
    ])->assertStatus(409);

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Wait->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 2,
        'public_message' => 'Please send a screenshot of the error.',
    ])->assertRedirect();

    $this->actingAs($this->customer)->get(route('customer.tickets.edit', $ticket->id))->assertNotFound();
});

it('enforces the ticket state graph and freezes then resumes business-time SLA intervals', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Dhaka'));
    $ticket = ticketForWorkflow($this->customer, $this->agent, 'critical');

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Start->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 1,
    ])->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);

    $this->travel(1)->hours();
    $this->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Wait->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 2,
        'public_message' => 'Please confirm which account is affected.',
    ])->assertRedirect();
    $paused = $ticket->fresh();
    expect($paused->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($paused->sla_consumed_seconds)->toBe(3600)
        ->and($paused->sla_due_at)->toBeNull()
        ->and($paused->sla_warning_at)->toBeNull();
    $this->assertDatabaseHas('ticket_sla_intervals', ['ticket_id' => $ticket->id, 'kind' => 'waiting', 'ended_at' => null]);

    $this->travel(2)->days();
    $this->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Resume->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 3,
    ])->assertRedirect();
    $resumed = $ticket->fresh();
    expect($resumed->status)->toBe(TicketStatus::InProgress)
        ->and($resumed->sla_consumed_seconds)->toBe(3600)
        ->and($resumed->sla_due_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-03 11:00')
        ->and(TicketSlaInterval::query()->where('ticket_id', $ticket->id)->where('kind', 'waiting')->whereNotNull('ended_at')->count())->toBe(1);

    $this->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Resolve->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 4,
        'public_message' => 'The issue is resolved and verified.',
    ])->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and(TicketSlaInterval::query()->where('ticket_id', $ticket->id)->where('kind', 'resolved')->whereNull('ended_at')->exists())->toBeTrue();

    $this->actingAs($this->customer)->patch(route('customer.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Reopen->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 5,
        'reason' => 'The issue returned after signing in again.',
        'public_message' => 'The error has returned after signing in again.',
    ])->assertRedirect();
    $reopened = $ticket->fresh();
    expect($reopened->status)->toBe(TicketStatus::InProgress)
        ->and($reopened->resolution_cycle)->toBe(2)
        ->and($reopened->resolved_at)->toBeNull()
        ->and($reopened->sla_consumed_seconds)->toBe(3600)
        ->and(TicketSlaInterval::query()->where('ticket_id', $ticket->id)->where('kind', 'resolved')->whereNotNull('ended_at')->count())->toBe(1);

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Resolve->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 6,
        'public_message' => 'Resolved after the second check.',
    ])->assertRedirect();
    $this->actingAs($this->customer)->patch(route('customer.tickets.transition', $ticket->id), [
        'transition' => TicketTransition::Close->value,
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 7,
    ])->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->fresh()->closed_at)->not->toBeNull()
        ->and(TicketSlaInterval::query()->where('ticket_id', $ticket->id)->where('kind', 'resolved')->whereNull('ended_at')->exists())->toBeFalse();
});

it('recalculates the original SLA budget on priority change without changing the pinned policy', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Dhaka'));
    $ticket = ticketForWorkflow($this->customer, $this->agent, 'high');
    $startedAt = $ticket->sla_started_at;
    $policyId = $ticket->sla_policy_id;
    $critical = Priority::query()->where('slug', 'critical')->firstOrFail();

    $this->travel(1)->hours();
    $this->actingAs($this->agent)->withHeaders(['Accept' => 'application/json'])->post(route('dashboard.tickets.priority', $ticket->id), [
        '_method' => 'PATCH',
        'operation_key' => (string) Str::uuid(),
        'lock_version' => 1,
        'priority_id' => $critical->id,
    ])->assertOk()->assertJsonPath('ticket.priority_id', $critical->id);

    $updated = $ticket->fresh();
    expect($updated->priority_id)->toBe($critical->id)
        ->and($updated->sla_target_seconds)->toBe(7200)
        ->and($updated->sla_consumed_seconds)->toBe(3600)
        ->and($updated->sla_started_at->equalTo($startedAt))->toBeTrue()
        ->and($updated->sla_policy_id)->toBe($policyId)
        ->and($updated->sla_due_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 11:00');
    $this->assertDatabaseHas('activity_entries', ['ticket_id' => $ticket->id, 'action' => 'ticket.priority_changed']);
});

it('calculates business deadlines across weekends and configured holidays', function (): void {
    $calendar = CalendarVersion::factory()->published()->create();
    foreach ([1, 2, 3, 4, 7] as $weekday) {
        CalendarWindow::factory()->create([
            'calendar_version_id' => $calendar->id,
            'weekday' => $weekday,
            'opens_at' => '09:00:00',
            'closes_at' => '18:00:00',
        ]);
    }

    $sla = app(SlaService::class);
    $thursday = CarbonImmutable::parse('2026-10-29 17:00:00', 'Asia/Dhaka');
    $projection = $sla->creationProjection($calendar, $thursday, 7200, 80);
    expect($projection['warning_at']->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 09:36')
        ->and($projection['due_at']->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-01 10:00');

    CalendarHoliday::factory()->create([
        'calendar_version_id' => $calendar->id,
        'holiday_date' => '2026-11-01',
        'name' => 'Test holiday',
    ]);
    $withHoliday = $sla->creationProjection($calendar, $thursday, 7200, 80);
    expect($withHoliday['warning_at']->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 09:36')
        ->and($withHoliday['due_at']->timezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-02 10:00')
        ->and($sla->businessSecondsBetween($calendar, $thursday, CarbonImmutable::parse('2026-11-02 10:00:00', 'Asia/Dhaka')))->toBe(7200);
});

it('records status mutations and messages atomically with idempotency receipts', function (): void {
    $ticket = ticketForWorkflow($this->customer, $this->agent);
    $operationKey = (string) Str::uuid();
    $payload = [
        'transition' => TicketTransition::Wait->value,
        'operation_key' => $operationKey,
        'lock_version' => $ticket->lock_version,
        'public_message' => 'Please provide a sample transaction reference.',
    ];

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.transition', $ticket->id), $payload)->assertRedirect();
    $this->patch(route('dashboard.tickets.transition', $ticket->id), $payload)->assertRedirect();

    expect($ticket->fresh()->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($ticket->messages()->where('visibility', 'public')->count())->toBe(1)
        ->and(ActivityEntry::query()->where('ticket_id', $ticket->id)->where('action', 'ticket.wait')->count())->toBe(1)
        ->and(DB::table('command_receipts')->where('operation_key', $operationKey)->whereNotNull('completed_at')->exists())->toBeTrue();
});

it('applies ticket bulk availability with per-record version checks', function (): void {
    $ticket = ticketForWorkflow($this->customer);
    $operationKey = (string) Str::uuid();
    $payload = [
        'operation_key' => $operationKey,
        'action' => 'disable',
        'selected_ids' => [$ticket->id],
        'expected_versions' => [$ticket->id => $ticket->lock_version],
    ];

    $this->actingAs($this->admin)->post(route('dashboard.tickets.bulk'), $payload)
        ->assertRedirect()->assertSessionHas('success', 'Bulk action completed for 1 tickets.');
    $this->post(route('dashboard.tickets.bulk'), $payload)->assertRedirect();

    expect($ticket->fresh()->is_active)->toBeFalse()
        ->and($ticket->fresh()->lock_version)->toBe(2);
});

it('records stale ticket versions and rejects non-admin ticket bulk actions', function (): void {
    $ticket = ticketForWorkflow($this->customer);
    $payload = [
        'operation_key' => (string) Str::uuid(),
        'action' => 'disable',
        'selected_ids' => [$ticket->id],
        'expected_versions' => [$ticket->id => $ticket->lock_version + 1],
    ];

    $this->actingAs($this->admin)->post(route('dashboard.tickets.bulk'), $payload)
        ->assertRedirect()->assertSessionHas('bulk_failures');
    expect($ticket->fresh()->is_active)->toBeTrue();

    $this->actingAs($this->agent)->post(route('dashboard.tickets.bulk'), $payload)->assertRedirect();
});

it('archives only closed tickets and restores them disabled', function (): void {
    $closedTicket = ticketForWorkflow($this->customer);
    $closedTicket->forceFill(['status' => TicketStatus::Closed])->save();
    $openTicket = ticketForWorkflow($this->otherCustomer);
    $operationKey = (string) Str::uuid();

    $this->actingAs($this->admin)->post(route('dashboard.tickets.bulk'), [
        'operation_key' => $operationKey,
        'action' => 'archive',
        'selected_ids' => [$closedTicket->id, $openTicket->id],
        'expected_versions' => [$closedTicket->id => $closedTicket->lock_version, $openTicket->id => $openTicket->lock_version],
    ])->assertRedirect()->assertSessionHas('bulk_failures');

    expect(Ticket::withTrashed()->findOrFail($closedTicket->id)->trashed())->toBeTrue()
        ->and(Ticket::withTrashed()->findOrFail($openTicket->id)->trashed())->toBeFalse();

    $archivedTicket = Ticket::withTrashed()->findOrFail($closedTicket->id);
    $this->post(route('dashboard.tickets.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'restore',
        'selected_ids' => [$archivedTicket->id],
        'expected_versions' => [$archivedTicket->id => $archivedTicket->lock_version],
    ])->assertRedirect();

    expect($archivedTicket->fresh()->trashed())->toBeFalse()->and($archivedTicket->fresh()->is_active)->toBeFalse();
});
