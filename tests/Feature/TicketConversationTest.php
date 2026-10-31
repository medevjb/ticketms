<?php

use App\Enums\TicketStatus;
use App\Models\ActivityEntry;
use App\Models\BusinessCalendar;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketSlaInterval;
use App\Models\User;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketRealtimeService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

function ticketForConversation(User $customer, ?User $agent = null, string $prioritySlug = 'high'): Ticket
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

it('keeps internal notes and their attachments private while allowing assigned staff to download them', function (): void {
    Storage::fake('local');
    $ticket = ticketForConversation($this->customer, $this->agent);
    $internalBody = 'Internal reproduction notes for the payment failure.';
    $secretName = 'private-diagnostic.txt';

    $this->actingAs($this->agent)->post(route('dashboard.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'internal',
        'body' => $internalBody,
        'attachments' => [UploadedFile::fake()->createWithContent($secretName, 'private diagnostic data')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $message = TicketMessage::query()->where('ticket_id', $ticket->id)->firstOrFail();
    $attachment = TicketAttachment::query()->where('ticket_message_id', $message->id)->firstOrFail();
    expect($message->visibility->value)->toBe('internal')
        ->and($attachment->disk)->toBe('local')
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();

    $this->actingAs($this->customer)->get(route('customer.tickets.show', $ticket))
        ->assertOk()->assertDontSee($internalBody)->assertDontSee($secretName);
    $this->get(route('dashboard.ticket-attachments.download', $attachment))->assertNotFound();

    $this->actingAs($this->agent)->get(route('dashboard.ticket-attachments.download', $attachment))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('rejects a MIME-spoofed attachment and discards earlier staged files', function (): void {
    Storage::fake('local');
    $ticket = ticketForConversation($this->customer, $this->agent);

    $this->actingAs($this->agent)->post(route('dashboard.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => 'I am attaching the requested report.',
        'attachments' => [
            UploadedFile::fake()->createWithContent('valid-notes.txt', 'A supported text attachment.'),
            UploadedFile::fake()->create('spoofed-report.pdf', 1, 'text/plain'),
        ],
    ])->assertSessionHasErrors('attachments');

    expect(TicketMessage::query()->where('ticket_id', $ticket->getKey())->exists())->toBeFalse()
        ->and(TicketAttachment::query()->where('ticket_id', $ticket->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles('ticket-files'))->toBe([]);
});

it('limits public conversation replies to agents and customers while admins can add internal notes', function (): void {
    $ticket = ticketForConversation($this->customer, $this->agent);
    $body = 'The support team has reviewed the latest details.';

    $this->actingAs($this->admin)->postJson(route('dashboard.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => $body,
    ])->assertForbidden();

    expect(TicketMessage::query()->where('ticket_id', $ticket->getKey())->where('body', $body)->exists())->toBeFalse();

    $this->get(route('dashboard.tickets.show', $ticket))->assertOk()
        ->assertDontSee('Write a reply…')->assertSee('Add internal note');

    $this->actingAs($this->agent)->postJson(route('dashboard.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => 'The assigned agent is following up with the customer.',
    ])->assertCreated()->assertJsonPath('record.visibility', 'public');

    $ticket->refresh();
    $this->actingAs($this->admin)->postJson(route('dashboard.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'internal',
        'body' => 'Admin-only investigation note for the support team.',
    ])->assertCreated()->assertJsonPath('record.visibility', 'internal');

    expect(TicketMessage::query()->where('ticket_id', $ticket->getKey())->where('visibility', 'internal')->exists())->toBeTrue();
});

it('prepares public agent replies for realtime delivery', function (): void {
    $ticket = ticketForConversation($this->customer, $this->agent);

    $this->actingAs($this->agent)->postJson(route('dashboard.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => 'The agent reply should be sent to the customer in realtime.',
    ])->assertCreated();

    $activity = ActivityEntry::query()
        ->where('ticket_id', $ticket->getKey())
        ->where('action', 'ticket.message_posted')
        ->firstOrFail();

    app(TicketRealtimeService::class)->publishActivity((int) $activity->getKey());

    expect($activity->visibility->value)->toBe('public');
});

it('resumes a waiting ticket when its customer posts a public reply and records only safe delivery data', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Dhaka'));
    $ticket = ticketForConversation($this->customer, $this->agent);
    $ticket->forceFill([
        'status' => TicketStatus::WaitingForCustomer,
        'sla_paused_at' => now('UTC')->subHours(2),
        'sla_due_at' => null,
        'sla_warning_at' => null,
    ])->save();
    $interval = TicketSlaInterval::factory()->create([
        'ticket_id' => $ticket->id,
        'kind' => 'waiting',
        'started_at' => now('UTC')->subHours(2),
        'ended_at' => null,
        'started_by' => $this->agent->id,
        'resolution_cycle' => 1,
    ]);
    $body = 'I have confirmed that the issue is still happening.';

    $this->actingAs($this->customer)->postJson(route('customer.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => $body,
    ])->assertCreated()->assertJsonPath('record.body', $body);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->fresh()->sla_paused_at)->toBeNull()
        ->and($ticket->fresh()->sla_due_at)->not->toBeNull()
        ->and($ticket->fresh()->sla_warning_at)->not->toBeNull()
        ->and($interval->fresh()->ended_at)->not->toBeNull()
        ->and(TicketMessage::query()->where('ticket_id', $ticket->id)->where('body', $body)->exists())->toBeTrue()
        ->and(ActivityEntry::query()->where('ticket_id', $ticket->id)->where('action', 'ticket.resumed_by_customer_reply')->exists())->toBeTrue();

    $payloads = DB::table('notification_deliveries')->whereIn('activity_entry_id', function ($query) use ($ticket): void {
        $query->select('id')->from('activity_entries')->where('ticket_id', $ticket->id);
    })->pluck('payload');
    foreach ($payloads as $payload) {
        expect((string) $payload)->not->toContain($body)
            ->not->toContain('ticket-files/');
    }
});

it('shows a neutral redaction marker to customers after public-message moderation and retains archived records', function (): void {
    $ticket = ticketForConversation($this->customer, $this->agent);
    $body = 'A public response containing information that should be hidden.';
    $message = TicketMessage::factory()->create([
        'ticket_id' => $ticket->id,
        'author_id' => $this->agent->id,
        'body' => $body,
    ]);

    $this->actingAs($this->admin)->postJson(route('dashboard.tickets.messages.moderate', [$ticket, $message]), [
        'operation_key' => (string) Str::uuid(),
        'expected_updated_at' => $message->updated_at->toIso8601String(),
        'action' => 'hide',
        'reason' => 'The reply disclosed protected information.',
    ])->assertOk()->assertJsonPath('record.is_active', false);

    $this->actingAs($this->customer)->get(route('customer.tickets.show', $ticket))
        ->assertOk()->assertSee('This reply is no longer available.')
        ->assertDontSee($body);

    $hidden = $message->fresh();
    $this->actingAs($this->admin)->postJson(route('dashboard.tickets.messages.moderate', [$ticket, $message]), [
        'operation_key' => (string) Str::uuid(),
        'expected_updated_at' => $hidden->updated_at->toIso8601String(),
        'action' => 'archive',
        'reason' => 'Archive this message from the active conversation.',
    ])->assertOk()->assertJsonPath('record.archived', true);

    expect(TicketMessage::withTrashed()->whereKey($message->id)->firstOrFail()->trashed())->toBeTrue();
});

it('requires a reason and public message to reopen a resolved ticket atomically', function (): void {
    $ticket = ticketForConversation($this->customer, $this->agent);
    $ticket->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now('UTC')])->save();
    TicketSlaInterval::factory()->create([
        'ticket_id' => $ticket->id,
        'kind' => 'resolved',
        'started_at' => now('UTC'),
        'started_by' => $this->agent->id,
        'resolution_cycle' => $ticket->resolution_cycle,
    ]);
    $this->actingAs($this->customer)->post(route('customer.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => 'The reported issue has returned on the latest attempt.',
    ])->assertSessionHasErrors('reopen');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and(TicketMessage::query()->where('ticket_id', $ticket->id)->exists())->toBeFalse();

    $this->post(route('customer.tickets.messages.store', $ticket), [
        'operation_key' => (string) Str::uuid(),
        'lock_version' => $ticket->lock_version,
        'visibility' => 'public',
        'body' => 'The reported issue has returned on the latest attempt.',
        'reopen' => true,
        'reason' => 'The customer confirmed recurrence after sign in.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->fresh()->resolution_cycle)->toBe(2)
        ->and(TicketMessage::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('starts staff and customer conversations with the escaped initial issue before any replies', function (): void {
    $ticket = ticketForConversation($this->customer, $this->agent);
    $ticket->update(['description' => "Original issue details\n<script>alert('unsafe')</script>"]);

    $this->actingAs($this->admin)->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()->assertSeeInOrder(['Conversation', 'Initial issue', e($ticket->description)], false)
        ->assertDontSee($ticket->description, false)->assertDontSee('No conversation activity yet');
    $this->actingAs($this->agent)->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()->assertSee('Initial issue')->assertSee($ticket->description);
    $this->actingAs($this->customer)->get(route('customer.tickets.show', $ticket))
        ->assertOk()->assertSeeInOrder(['Conversation', 'Initial issue', e($ticket->description)], false)
        ->assertDontSee($ticket->description, false)->assertDontSee('There are no replies yet.');

    expect($ticket->messages()->count())->toBe(0);
});

it('previews conversation images inline with the same ownership and visibility checks as downloads', function (): void {
    Storage::fake('local');
    $ticket = ticketForConversation($this->customer, $this->agent);
    $message = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->customer->id, 'visibility' => 'public']);
    $attachment = TicketAttachment::factory()->create([
        'ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'uploaded_by' => $this->customer->id,
        'path' => 'ticket-files/screenshot.png', 'mime_type' => 'image/png', 'original_name' => 'Screenshot.png',
    ]);
    Storage::disk('local')->put($attachment->path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jc1kAAAAASUVORK5CYII='));

    $this->actingAs($this->agent)->get(route('dashboard.ticket-attachments.preview', $attachment))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('dashboard.tickets.show', $ticket))->assertOk()->assertSee('alt="Screenshot.png"', false);
    $this->actingAs($this->customer)->get(route('customer.ticket-attachments.preview', $attachment))->assertOk();
    $this->actingAs($this->otherCustomer)->get(route('customer.ticket-attachments.preview', $attachment))->assertNotFound();

    $message->forceFill(['is_active' => false])->save();
    $this->actingAs($this->customer)->get(route('customer.ticket-attachments.preview', $attachment))->assertNotFound();
});

it('does not preview internal images for customers or render non-image files inline', function (): void {
    Storage::fake('local');
    $ticket = ticketForConversation($this->customer, $this->agent);
    $message = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'visibility' => 'internal']);
    $attachment = TicketAttachment::factory()->create([
        'ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'uploaded_by' => $this->agent->id,
        'path' => 'ticket-files/private.png', 'mime_type' => 'image/png',
    ]);
    Storage::disk('local')->put($attachment->path, 'private image');
    $this->actingAs($this->customer)->get(route('customer.ticket-attachments.preview', $attachment))->assertNotFound();
    $attachment->forceFill(['mime_type' => 'text/plain'])->save();
    $this->actingAs($this->admin)->get(route('dashboard.ticket-attachments.preview', $attachment))->assertNotFound();
});
