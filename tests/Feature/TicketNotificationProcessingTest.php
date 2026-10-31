<?php

use App\Enums\EscalationStage;
use App\Enums\TicketStatus;
use App\Jobs\DeliverTicketNotification;
use App\Models\ActivityEntry;
use App\Models\AgentProfile;
use App\Models\BusinessCalendar;
use App\Models\Category;
use App\Models\NotificationDelivery;
use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\TicketEscalation;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\Tickets\NotificationDeliveryService;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketEscalationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

function ticketForNotificationTesting(User $customer, ?User $agent = null, string $prioritySlug = 'critical'): Ticket
{
    $policy = SlaPolicy::query()->firstOrFail();
    $priority = Priority::query()->where('slug', $prioritySlug)->firstOrFail();
    $target = SlaPolicyTarget::query()->where('sla_policy_id', $policy->getKey())->where('priority_id', $priority->getKey())->firstOrFail();
    $calendar = BusinessCalendar::query()->firstOrFail();
    $startedAt = CarbonImmutable::now('UTC')->startOfSecond();
    $projection = app(SlaService::class)->creationProjection($calendar, $startedAt, (int) $target->target_seconds, (int) $policy->warning_percent);

    return Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'created_by' => $customer->getKey(),
        'assigned_agent_id' => $agent?->getKey(),
        'category_id' => Category::query()->active()->firstOrFail()->getKey(),
        'priority_id' => $priority->getKey(),
        'sla_policy_id' => $policy->getKey(),
        'business_calendar_id' => $calendar->getKey(),
        'sla_target_seconds' => $target->target_seconds,
        'sla_started_at' => $startedAt,
        'sla_warning_at' => $projection['warning_at'],
        'sla_due_at' => $projection['due_at'],
    ])->refresh();
}

function ticketNotificationPayload(Ticket $ticket, ActivityEntry $activity, string $event, string $summary): array
{
    return [
        'schema_version' => 1,
        'event' => $event,
        'ticket_id' => $ticket->getKey(),
        'ticket_number' => $ticket->number,
        'activity_id' => $activity->getKey(),
        'summary' => $summary,
    ];
}

function makeTicketNotification(User $recipient, Ticket $ticket, string $summary): DatabaseNotification
{
    $activity = ActivityEntry::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'actor_id' => $ticket->customer_id,
        'action' => 'ticket.message_posted',
        'visibility' => 'public',
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->getKey(),
    ]);

    return $recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketActivityNotification::class,
        'data' => ticketNotificationPayload($ticket, $activity, 'ticket.message_posted', $summary),
    ]);
}

it('delivers database notifications with a stable UUID and safe content', function (): void {
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $activity = ActivityEntry::factory()->create([
        'ticket_id' => $ticket->id,
        'actor_id' => $this->customer->id,
        'action' => 'ticket.message_posted',
        'visibility' => 'public',
    ]);
    $uuid = (string) Str::uuid();
    $delivery = NotificationDelivery::factory()->create([
        'activity_entry_id' => $activity->id,
        'recipient_id' => $this->agent->id,
        'channel' => 'database',
        'notification_uuid' => $uuid,
        'payload' => ticketNotificationPayload($ticket, $activity, 'ticket.message_posted', 'There is a new response on your ticket.'),
    ]);

    app(NotificationDeliveryService::class)->deliver($delivery->id);
    app(NotificationDeliveryService::class)->deliver($delivery->id);

    $this->assertDatabaseHas('notification_deliveries', [
        'id' => $delivery->id,
        'delivery_status' => 'sent',
        'notification_uuid' => $uuid,
    ]);
    expect($this->agent->notifications()->whereKey($uuid)->count())->toBe(1);
    $data = $this->agent->notifications()->whereKey($uuid)->firstOrFail()->data;
    expect($data)->toHaveKeys(['event', 'ticket_id', 'ticket_number', 'activity_id', 'summary'])
        ->and(json_encode($data))->not->toContain('message body')
        ->not->toContain('private-file.txt')
        ->not->toContain('ticket-files/');
});

it('persists a retry for failed mail without changing the database delivery intent', function (): void {
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $activity = ActivityEntry::factory()->create(['ticket_id' => $ticket->id]);
    $payload = ticketNotificationPayload($ticket, $activity, 'ticket.created', 'Ticket created.');
    $mailDelivery = NotificationDelivery::factory()->create([
        'activity_entry_id' => $activity->id,
        'recipient_id' => $this->admin->id,
        'channel' => 'mail',
        'payload' => $payload,
    ]);
    $databaseDelivery = NotificationDelivery::factory()->create([
        'activity_entry_id' => $activity->id,
        'recipient_id' => $this->admin->id,
        'channel' => 'database',
        'payload' => $payload,
    ]);

    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('private provider response'));
    app(NotificationDeliveryService::class)->deliver($mailDelivery->id);

    $this->assertDatabaseHas('notification_deliveries', [
        'id' => $mailDelivery->id,
        'delivery_status' => 'pending',
        'attempts' => 1,
        'last_error_code' => 'RuntimeException',
    ]);
    $this->assertDatabaseHas('notification_deliveries', [
        'id' => $databaseDelivery->id,
        'delivery_status' => 'pending',
        'attempts' => 0,
    ]);
    expect($mailDelivery->fresh()->last_error_code)->not->toContain('private provider response');
});

it('dispatches each channel to its dedicated queue and uses the durable activity intents', function (): void {
    Queue::fake();
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $activity = ActivityEntry::factory()->create(['ticket_id' => $ticket->id]);
    NotificationDelivery::factory()->create([
        'activity_entry_id' => $activity->id,
        'recipient_id' => $this->customer->id,
        'channel' => 'database',
        'payload' => ticketNotificationPayload($ticket, $activity, 'ticket.created', 'Ticket created.'),
    ]);
    NotificationDelivery::factory()->create([
        'activity_entry_id' => $activity->id,
        'recipient_id' => $this->customer->id,
        'channel' => 'mail',
        'payload' => ticketNotificationPayload($ticket, $activity, 'ticket.created', 'Ticket created.'),
    ]);

    expect(app(NotificationDeliveryService::class)->dispatchForActivity($activity->id))->toBe(2);
    Queue::assertPushedOn('notifications-database', DeliverTicketNotification::class);
    Queue::assertPushedOn('notifications-mail', DeliverTicketNotification::class);
});

it('scopes the notification bar to current ticket access and only marks the actor’s notifications read', function (): void {
    $ownTicket = ticketForNotificationTesting($this->customer, $this->agent);
    $otherTicket = ticketForNotificationTesting($this->otherCustomer, $this->agent);
    $ownNotification = makeTicketNotification($this->customer, $ownTicket, 'Your ticket was updated.');
    makeTicketNotification($this->customer, $otherTicket, 'Another customer ticket changed.');

    $this->actingAs($this->customer)->get(route('customer.notifications.index'))
        ->assertOk()->assertSee($ownTicket->number)->assertDontSee($otherTicket->number);
    $this->post(route('customer.notifications.read', $ownNotification->id))->assertRedirect();
    $this->assertDatabaseHas('notifications', ['id' => $ownNotification->id, 'notifiable_id' => $this->customer->id]);

    $this->actingAs($this->otherCustomer)->post(route('customer.notifications.read', $ownNotification->id))->assertNotFound();
    $this->post(route('customer.notifications.read-all'))->assertRedirect();
});

it('renders the unread count beside the notification icon in the topbar', function (): void {
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    makeTicketNotification($this->agent, $ticket, 'A ticket needs your attention.');

    $this->actingAs($this->agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('href="'.route('dashboard.notifications.index').'" class="topbar-btn"', false)
        ->assertSee('aria-label="Notifications, 1 unread"', false)
        ->assertSee('<span class="ticket-notification-count" aria-hidden="true">1</span>', false);
});

it('removes a ticket notification from the previous assignee after reassignment', function (): void {
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $notification = makeTicketNotification($this->agent, $ticket, 'The ticket was updated.');
    $replacementAgent = User::factory()->create();
    $replacementAgent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    AgentProfile::factory()->create(['user_id' => $replacementAgent->getKey(), 'auto_assignment_enabled' => false]);

    $this->actingAs($this->admin)->patch(route('dashboard.tickets.assignment', $ticket->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'agent_id' => $replacementAgent->getKey(),
    ])->assertRedirect();

    expect($ticket->fresh()->assigned_agent_id)->toBe($replacementAgent->getKey());

    $this->actingAs($this->agent)->get(route('dashboard.notifications.index'))
        ->assertOk()
        ->assertViewHas('notifications', fn ($notifications): bool => ! $notifications->getCollection()->contains('id', $notification->getKey()));
    $this->post(route('dashboard.notifications.read', $notification->getKey()))->assertNotFound();
});

it('skips a queued notification when the recipient loses ticket access before delivery', function (): void {
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $activity = ActivityEntry::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'actor_id' => $this->customer->getKey(),
        'action' => 'ticket.assigned',
        'visibility' => 'public',
    ]);
    $delivery = NotificationDelivery::factory()->create([
        'activity_entry_id' => $activity->getKey(),
        'recipient_id' => $this->agent->getKey(),
        'channel' => 'database',
        'payload' => ticketNotificationPayload($ticket, $activity, 'ticket.assigned', 'The ticket was assigned.'),
    ]);

    $replacementAgent = User::factory()->create();
    $replacementAgent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    AgentProfile::factory()->create(['user_id' => $replacementAgent->getKey(), 'auto_assignment_enabled' => false]);
    $this->actingAs($this->admin)->patch(route('dashboard.tickets.assignment', $ticket->getKey()), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'agent_id' => $replacementAgent->getKey(),
    ])->assertRedirect();

    app(NotificationDeliveryService::class)->deliver((int) $delivery->getKey());

    expect($delivery->fresh()->delivery_status->value)->toBe('skipped')
        ->and($this->agent->notifications()->whereKey($delivery->notification_uuid)->exists())->toBeFalse();
});

it('creates one approaching and one breached SLA event per resolution cycle with current-stage deduplication', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Dhaka'));
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $ticket->forceFill(['status' => TicketStatus::InProgress])->save();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:40:00', 'Asia/Dhaka'));
    app(TicketEscalationService::class)->evaluate($ticket->id);
    app(TicketEscalationService::class)->evaluate($ticket->id);

    expect(TicketEscalation::query()->where('ticket_id', $ticket->id)->where('stage', EscalationStage::Approaching)->count())->toBe(1)
        ->and(TicketEscalation::query()->where('ticket_id', $ticket->id)->where('stage', EscalationStage::Breached)->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 11:05:00', 'Asia/Dhaka'));
    app(TicketEscalationService::class)->evaluate($ticket->id);

    expect(TicketEscalation::query()->where('ticket_id', $ticket->id)->where('stage', EscalationStage::Breached)->count())->toBe(1)
        ->and($ticket->fresh()->first_breached_at)->not->toBeNull()
        ->and(DB::table('notification_deliveries')->whereIn('activity_entry_id', function ($query) use ($ticket): void {
            $query->select('id')->from('activity_entries')->where('ticket_id', $ticket->id)->whereIn('action', ['ticket.sla_approaching', 'ticket.sla_breached']);
        })->where('recipient_id', $this->customer->id)->count())->toBe(0);
});

it('records only the breached stage when the first SLA scan occurs after the deadline', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Dhaka'));
    $ticket = ticketForNotificationTesting($this->customer, $this->agent);
    $ticket->forceFill(['status' => TicketStatus::InProgress])->save();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 11:15:00', 'Asia/Dhaka'));
    app(TicketEscalationService::class)->evaluate($ticket->id);

    expect(TicketEscalation::query()->where('ticket_id', $ticket->id)->pluck('stage')->all())->toBe([EscalationStage::Breached]);
});
