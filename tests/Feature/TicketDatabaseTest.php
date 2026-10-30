<?php

use App\Enums\MessageVisibility;
use App\Enums\TicketStatus;
use App\Models\ActivityEntry;
use App\Models\CalendarVersion;
use App\Models\Category;
use App\Models\NotificationDelivery;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketingSetting;
use App\Models\TicketMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('ticket relationships retain historical configuration after archival', function () {
    $ticket = Ticket::factory()->create();
    $category = $ticket->category;
    $calendar = $ticket->calendarVersion;

    $category->delete();
    $calendar->delete();

    expect($ticket->fresh()->category->is($category))->toBeTrue()
        ->and($ticket->fresh()->calendarVersion->is($calendar))->toBeTrue()
        ->and($ticket->customer->ownedTickets->contains($ticket))->toBeTrue()
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->sla_started_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('inactive and archived categories are excluded from available selections', function () {
    $active = Category::factory()->create();
    Category::factory()->create(['is_active' => false]);
    Category::factory()->create()->delete();

    expect(Category::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

test('soft deletion preserves ticket contents and independent child archives', function () {
    $ticket = Ticket::factory()->closed()->create();
    $message = TicketMessage::factory()->for($ticket)->create();
    $hiddenMessage = TicketMessage::factory()->for($ticket)->create();
    $hiddenMessage->delete();

    $ticket->delete();
    $this->assertSoftDeleted($ticket);
    $ticket->restore();

    expect($ticket->messages->modelKeys())->toBe([$message->id]);
    $this->assertSoftDeleted($hiddenMessage);
});

test('attachment relations connect to the matching conversation and owner', function () {
    $message = TicketMessage::factory()->create(['visibility' => 'internal']);
    $attachment = TicketAttachment::factory()->create([
        'ticket_id' => $message->ticket_id,
        'ticket_message_id' => $message->id,
    ]);

    expect($attachment->message->visibility)->toBe(MessageVisibility::Internal)
        ->and($attachment->ticket->is($message->ticket))->toBeTrue()
        ->and($message->attachments->contains($attachment))->toBeTrue();
});

test('ticket configuration bootstrap creates approved targets and is idempotent', function () {
    $this->seed(TicketingConfigurationSeeder::class);
    $this->seed(TicketingConfigurationSeeder::class);

    $policy = TicketingSetting::query()->findOrFail(1)->currentSlaPolicy;

    expect($policy->calendarVersion->timezone)->toBe('Asia/Dhaka')
        ->and($policy->calendarVersion->windows->pluck('weekday')->sort()->values()->all())->toBe([1, 2, 3, 4, 7])
        ->and($policy->targets->pluck('target_seconds')->sort()->values()->all())->toBe([7200, 28800, 86400, 172800])
        ->and(CalendarVersion::query()->count())->toBe(1)
        ->and(Role::query()->where('is_system', true)->count())->toBe(3);
});

test('spatie uses isolated access tables rather than tyro role storage', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $permission = Permission::factory()->create(['name' => 'tickets.view']);
    $role->givePermissionTo($permission);

    $user->assignRole($role);

    expect($user->fresh()->roles->modelKeys())->toBe([$role->id])
        ->and($user->hasPermissionTo('tickets.view'))->toBeTrue();
    $this->assertDatabaseHas('access_model_has_roles', ['model_id' => $user->id, 'role_id' => $role->id]);
    $this->assertDatabaseCount('user_roles', 0);
});

test('sensitive ticket fields cannot be mass assigned through content input', function () {
    $ticket = new Ticket;

    $ticket->fill(['subject' => 'Subject', 'description' => 'Body', 'customer_id' => 999, 'status' => 'closed']);

    expect($ticket->getAttributes())->toHaveKey('subject', 'Subject')
        ->not->toHaveKey('customer_id')
        ->not->toHaveKey('status');
});

test('domain records activity and notification intent roll back together', function () {
    $ticketCount = Ticket::query()->count();

    expect(function (): void {
        DB::transaction(function (): void {
            $activity = ActivityEntry::factory()->create();
            NotificationDelivery::factory()->create(['activity_entry_id' => $activity->id]);

            throw new RuntimeException('Simulated business operation failure');
        });
    })->toThrow(RuntimeException::class);

    expect(Ticket::query()->count())->toBe($ticketCount);
    $this->assertDatabaseCount('activity_entries', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('an attachment cannot reference a message belonging to another ticket', function () {
    $message = TicketMessage::factory()->create();
    $otherTicket = Ticket::factory()->create();

    expect(fn () => TicketAttachment::factory()->create([
        'ticket_id' => $otherTicket->id,
        'ticket_message_id' => $message->id,
    ]))->toThrow(QueryException::class);
});

test('reserved identities are not reusable after soft deletion', function () {
    $category = Category::factory()->create(['slug' => 'account']);
    $category->delete();

    expect(fn () => Category::factory()->create(['slug' => 'account']))->toThrow(QueryException::class);
});
