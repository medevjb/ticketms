<?php

use App\Enums\ActorKind;
use App\Enums\TicketStatus;
use App\Models\ActivityEntry;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\NotificationDelivery;
use App\Models\Priority;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\Authorization\UserLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);

    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->otherAgent = User::factory()->create();
    $this->customer = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $this->agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $this->otherAgent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $this->customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    AgentProfile::factory()->create(['user_id' => $this->agent->getKey(), 'auto_assignment_enabled' => false]);
    AgentProfile::factory()->create(['user_id' => $this->otherAgent->getKey(), 'auto_assignment_enabled' => false]);
});

function ticketForAssignment(User $customer, ?User $agent = null, string $status = 'open'): Ticket
{
    $factory = Ticket::factory();
    if ($status === 'waiting_customer') {
        $factory = $factory->waiting();
    } elseif ($status === 'resolved') {
        $factory = $factory->resolved();
    } elseif ($status === 'closed') {
        $factory = $factory->closed();
    }

    return $factory->create([
        'customer_id' => $customer->getKey(),
        'created_by' => $customer->getKey(),
        'assigned_agent_id' => $agent?->getKey(),
        'status' => $status,
    ])->refresh();
}

it('automatically selects the lowest workload before considering assignment recency', function (): void {
    $agentProfile = AgentProfile::query()->where('user_id', $this->agent->getKey())->firstOrFail();
    $otherProfile = AgentProfile::query()->where('user_id', $this->otherAgent->getKey())->firstOrFail();
    $agentProfile->forceFill(['auto_assignment_enabled' => true, 'last_auto_assigned_at' => null])->save();
    $otherProfile->forceFill(['auto_assignment_enabled' => true, 'last_auto_assigned_at' => CarbonImmutable::now()->subDays(5)])->save();
    ticketForAssignment($this->customer, $this->agent);

    $this->actingAs($this->customer)->post(route('customer.tickets.store'), [
        'operation_key' => (string) Str::uuid(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => Priority::query()->where('slug', 'medium')->firstOrFail()->getKey(),
        'subject' => 'Automatically assigned request',
        'description' => 'The least-loaded eligible agent should receive this ticket.',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Automatically assigned request')->firstOrFail();
    expect($ticket->assigned_agent_id)->toBe($this->otherAgent->getKey())
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->lock_version)->toBe(2)
        ->and($otherProfile->fresh()->last_auto_assigned_at)->not->toBeNull();
    $activity = ActivityEntry::query()->where('ticket_id', $ticket->getKey())->where('action', 'ticket.auto_assigned')->firstOrFail();
    expect($activity->actor_kind)->toBe(ActorKind::System)
        ->and($activity->actor_id)->toBeNull()
        ->and(NotificationDelivery::query()->where('activity_entry_id', $activity->getKey())->count())->toBeGreaterThan(0);
});

it('prefers a null automatic assignment timestamp when workload is tied', function (): void {
    $agentProfile = AgentProfile::query()->where('user_id', $this->agent->getKey())->firstOrFail();
    $otherProfile = AgentProfile::query()->where('user_id', $this->otherAgent->getKey())->firstOrFail();
    $agentProfile->forceFill(['auto_assignment_enabled' => true, 'last_auto_assigned_at' => CarbonImmutable::now()->subDays(2)])->save();
    $otherProfile->forceFill(['auto_assignment_enabled' => true, 'last_auto_assigned_at' => null])->save();

    $this->actingAs($this->customer)->post(route('customer.tickets.store'), [
        'operation_key' => (string) Str::uuid(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => Priority::query()->where('slug', 'medium')->firstOrFail()->getKey(),
        'subject' => 'Tie-break assignment request',
        'description' => 'A never-assigned eligible agent wins an equal-workload tie.',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Tie-break assignment request')->firstOrFail();
    expect($ticket->assigned_agent_id)->toBe($this->otherAgent->getKey());
});

it('uses the lower user id when workload and automatic assignment timestamps tie', function (): void {
    $timestamp = CarbonImmutable::now()->subDays(2);
    AgentProfile::query()->where('user_id', $this->agent->getKey())->update([
        'auto_assignment_enabled' => true,
        'last_auto_assigned_at' => $timestamp,
    ]);
    AgentProfile::query()->where('user_id', $this->otherAgent->getKey())->update([
        'auto_assignment_enabled' => true,
        'last_auto_assigned_at' => $timestamp,
    ]);

    $this->actingAs($this->customer)->post(route('customer.tickets.store'), [
        'operation_key' => (string) Str::uuid(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => Priority::query()->where('slug', 'medium')->firstOrFail()->getKey(),
        'subject' => 'Final assignment tie-break request',
        'description' => 'A final deterministic tie selects the lower user id.',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Final assignment tie-break request')->firstOrFail();
    expect($ticket->assigned_agent_id)->toBe(min($this->agent->getKey(), $this->otherAgent->getKey()));
});

it('leaves a newly created ticket unassigned and records why when no automatic candidate is eligible', function (): void {
    $this->actingAs($this->customer)->post(route('customer.tickets.store'), [
        'operation_key' => (string) Str::uuid(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => Priority::query()->where('slug', 'medium')->firstOrFail()->getKey(),
        'subject' => 'No eligible assignment request',
        'description' => 'Keep this ticket in the unassigned queue.',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'No eligible assignment request')->firstOrFail();
    expect($ticket->assigned_agent_id)->toBeNull();
    $this->assertDatabaseHas('activity_entries', [
        'ticket_id' => $ticket->getKey(),
        'action' => 'ticket.auto_assignment_skipped',
    ]);
});

it('allows an administrator to assign an active ticket without changing its status or SLA clock', function (): void {
    $ticket = ticketForAssignment($this->customer);
    $dueAt = $ticket->sla_due_at;

    $this->actingAs($this->admin)->withHeaders(['Accept' => 'application/json'])->post(route('dashboard.tickets.assignment', $ticket->getKey()), [
        '_method' => 'PATCH',
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'agent_id' => $this->agent->getKey(),
    ])->assertOk()->assertJsonPath('ticket.assigned_agent_id', $this->agent->getKey());

    $updated = $ticket->fresh();
    expect($updated->assigned_agent_id)->toBe($this->agent->getKey())
        ->and($updated->status)->toBe(TicketStatus::Open)
        ->and($updated->sla_started_at->equalTo($ticket->sla_started_at))->toBeTrue()
        ->and($updated->sla_due_at->equalTo($dueAt))->toBeTrue()
        ->and($updated->lock_version)->toBe(2);
    $this->assertDatabaseHas('activity_entries', ['ticket_id' => $ticket->getKey(), 'action' => 'ticket.assigned']);
});

it('allows administrators to reassign an open ticket from one agent to another', function (): void {
    $ticket = ticketForAssignment($this->customer, $this->agent);
    $slaStartedAt = $ticket->sla_started_at;
    $slaDueAt = $ticket->sla_due_at;

    $this->actingAs($this->admin)->patch(route('dashboard.tickets.assignment', $ticket->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'agent_id' => $this->otherAgent->getKey(),
    ])->assertRedirect();

    $updated = $ticket->fresh();
    expect($updated->assigned_agent_id)->toBe($this->otherAgent->getKey())
        ->and($updated->status)->toBe(TicketStatus::Open)
        ->and($updated->sla_started_at->equalTo($slaStartedAt))->toBeTrue()
        ->and($updated->sla_due_at->equalTo($slaDueAt))->toBeTrue();
    $this->assertDatabaseHas('activity_entries', ['ticket_id' => $ticket->getKey(), 'action' => 'ticket.assigned']);
});

it('revokes the previous agent attachment access immediately after reassignment', function (): void {
    Storage::fake('local');
    $ticket = ticketForAssignment($this->customer, $this->agent);
    $attachment = TicketAttachment::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'uploaded_by' => $this->agent->getKey(),
        'disk' => 'local',
        'path' => 'ticket-files/reassigned-private.txt',
    ]);
    Storage::disk('local')->put($attachment->path, 'private customer data');

    $this->actingAs($this->agent)->get(route('dashboard.ticket-attachments.download', $attachment))->assertOk();

    $this->actingAs($this->admin)->patch(route('dashboard.tickets.assignment', $ticket->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'agent_id' => $this->otherAgent->getKey(),
    ])->assertRedirect();

    $this->actingAs($this->agent)->get(route('dashboard.ticket-attachments.download', $attachment))->assertNotFound();
    $this->actingAs($this->otherAgent)->get(route('dashboard.ticket-attachments.download', $attachment))->assertOk();
});

it('lets an eligible agent claim an unassigned ticket once and rejects a competing claim', function (): void {
    $ticket = ticketForAssignment($this->customer);

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.claim', $ticket->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
    ])->assertRedirect(route('dashboard.tickets.show', $ticket));

    $updated = $ticket->fresh();
    expect($updated->assigned_agent_id)->toBe($this->agent->getKey())
        ->and($updated->status)->toBe(TicketStatus::Open)
        ->and($updated->lock_version)->toBe(2);

    $this->actingAs($this->otherAgent)->patch(route('dashboard.tickets.claim', $ticket->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $updated->lock_version,
    ])->assertStatus(409);
    expect($ticket->fresh()->assigned_agent_id)->toBe($this->agent->getKey());
});

it('keeps manual assignment administrator-only and rejects stale ticket versions', function (): void {
    $ticket = ticketForAssignment($this->customer);
    $payload = [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'agent_id' => $this->agent->getKey(),
    ];

    $this->actingAs($this->agent)->patch(route('dashboard.tickets.assignment', $ticket->getKey()), $payload)->assertForbidden();
    $this->actingAs($this->admin)->patch(route('dashboard.tickets.assignment', $ticket->getKey()), $payload)->assertRedirect();
    $this->patch(route('dashboard.tickets.assignment', $ticket->getKey()), [
        ...$payload,
        'operation_key' => (string) Str::uuid(),
    ])->assertStatus(409);
});

it('atomically unassigns a disabled agent while retaining terminal assignments', function (): void {
    $open = ticketForAssignment($this->customer, $this->agent);
    $waiting = ticketForAssignment($this->customer, $this->agent, 'waiting_customer');
    $resolved = ticketForAssignment($this->customer, $this->agent, 'resolved');
    $closed = ticketForAssignment($this->customer, $this->agent, 'closed');

    app(UserLifecycleService::class)->setActive($this->agent, false, $this->admin);

    expect($open->fresh()->assigned_agent_id)->toBeNull()
        ->and($open->fresh()->status)->toBe(TicketStatus::Open)
        ->and($open->fresh()->lock_version)->toBe($open->lock_version + 1)
        ->and($waiting->fresh()->assigned_agent_id)->toBeNull()
        ->and($waiting->fresh()->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($resolved->fresh()->assigned_agent_id)->toBe($this->agent->getKey())
        ->and($closed->fresh()->assigned_agent_id)->toBe($this->agent->getKey());
    $this->assertDatabaseHas('activity_entries', ['ticket_id' => $open->getKey(), 'action' => 'ticket.unassigned_agent_deactivated']);
});

it('only administrators can change agent automatic-assignment eligibility', function (): void {
    $this->actingAs($this->admin)->get(route('dashboard.ticket-agents.index'))->assertOk();
    $this->actingAs($this->admin)->patch(route('dashboard.ticket-agents.auto-assignment', $this->agent->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'auto_assignment_enabled' => true,
    ])->assertRedirect();

    expect($this->agent->agentProfile->fresh()->auto_assignment_enabled)->toBeTrue();
    $this->actingAs($this->agent)->patch(route('dashboard.ticket-agents.auto-assignment', $this->agent->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'auto_assignment_enabled' => false,
    ])->assertRedirect();
    expect($this->agent->agentProfile->fresh()->auto_assignment_enabled)->toBeTrue();
});
