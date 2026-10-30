<?php

use App\Models\ActivityEntry;
use App\Models\CalendarVersion;
use App\Models\CommandReceipt;
use App\Models\ImportRun;
use App\Models\NotificationDelivery;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketEscalation;
use App\Models\TicketExternalReference;
use App\Models\TicketingSetting;
use App\Models\TicketMessage;
use App\Models\TicketSlaInterval;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a ticket cannot pin a calendar from another policy', function () {
    $ticket = Ticket::factory()->create();
    $otherCalendar = CalendarVersion::factory()->published()->create();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update([
        'calendar_version_id' => $otherCalendar->id,
    ]))->toThrow(QueryException::class);
});

test('ticket target snapshots must match their pinned policy and priority', function () {
    $ticket = Ticket::factory()->create();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update([
        'sla_target_seconds' => 12345,
    ]))->toThrow(QueryException::class);
});

test('a referenced policy target cannot be edited underneath a ticket', function () {
    $ticket = Ticket::factory()->create();

    expect(fn () => SlaPolicyTarget::query()->where('sla_policy_id', $ticket->sla_policy_id)->update([
        'target_seconds' => 12345,
    ]))->toThrow(QueryException::class);
});

test('invalid ticket lifecycle states are rejected by mysql', function (array $changes) {
    $ticket = Ticket::factory()->create();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update($changes))
        ->toThrow(QueryException::class);
})->with([
    'unknown status' => [['status' => 'cancelled']],
    'waiting without pause' => [['status' => 'waiting_customer', 'sla_due_at' => null, 'sla_warning_at' => null]],
    'closed without resolution' => [['status' => 'closed', 'sla_due_at' => null, 'sla_warning_at' => null]],
    'archive live obligation' => [['deleted_at' => '2026-10-29 10:00:00']],
    'empty subject' => [['subject' => '   ']],
    'zero version' => [['lock_version' => 0]],
]);

test('only one exclusion interval can remain open for a ticket', function () {
    $ticket = Ticket::factory()->waiting()->create();
    TicketSlaInterval::factory()->create(['ticket_id' => $ticket->id]);

    expect(fn () => TicketSlaInterval::factory()->create(['ticket_id' => $ticket->id]))
        ->toThrow(QueryException::class);
});

test('closed intervals release the unique open interval slot', function () {
    $ticket = Ticket::factory()->create();
    $interval = TicketSlaInterval::factory()->create(['ticket_id' => $ticket->id]);
    $interval->forceFill(['ended_at' => now()])->save();

    $nextInterval = TicketSlaInterval::factory()->create(['ticket_id' => $ticket->id]);

    expect($nextInterval->exists)->toBeTrue();
});

test('an interval cannot end before it starts', function () {
    expect(fn () => TicketSlaInterval::factory()->create([
        'started_at' => '2026-10-29 10:00:00',
        'ended_at' => '2026-10-29 09:00:00',
    ]))->toThrow(QueryException::class);
});

test('escalation activity must belong to the same ticket', function () {
    $activity = ActivityEntry::factory()->create();
    $otherTicket = Ticket::factory()->create();

    expect(fn () => TicketEscalation::factory()->create([
        'ticket_id' => $otherTicket->id,
        'activity_entry_id' => $activity->id,
    ]))->toThrow(QueryException::class);
});

test('escalation stages cannot be duplicated within a resolution cycle', function () {
    $escalation = TicketEscalation::factory()->create();

    expect(fn () => TicketEscalation::factory()->create([
        'ticket_id' => $escalation->ticket_id,
        'resolution_cycle' => $escalation->resolution_cycle,
        'stage' => $escalation->stage,
    ]))->toThrow(QueryException::class);
});

test('audit deduplication cannot be bypassed using a null subject', function () {
    $activity = ActivityEntry::factory()->create();

    expect(fn () => DB::table('activity_entries')->where('id', $activity->id)->update([
        'subject_id' => null,
    ]))->toThrow(QueryException::class);
});

test('duplicate audit operations are rejected', function () {
    $activity = ActivityEntry::factory()->create();

    expect(fn () => ActivityEntry::factory()->create([
        'operation_key' => $activity->operation_key,
        'action' => $activity->action,
        'subject_type' => $activity->subject_type,
        'subject_id' => $activity->subject_id,
    ]))->toThrow(QueryException::class);
});

test('external references cannot use another import source', function () {
    $run = ImportRun::factory()->create(['source' => 'CRM']);

    expect(fn () => TicketExternalReference::factory()->create([
        'import_run_id' => $run->id,
        'source' => 'OtherCRM',
    ]))->toThrow(QueryException::class);
});

test('external identities are case sensitive and survive ticket archival', function () {
    $run = ImportRun::factory()->create();
    $ticket = Ticket::factory()->closed()->create();
    TicketExternalReference::factory()->create([
        'import_run_id' => $run->id, 'source' => $run->source,
        'external_id' => 'CRM-1', 'ticket_id' => $ticket->id,
    ]);
    $ticket->delete();
    TicketExternalReference::factory()->create([
        'import_run_id' => $run->id, 'source' => $run->source, 'external_id' => 'crm-1',
    ]);

    expect(fn () => TicketExternalReference::factory()->create([
        'import_run_id' => $run->id, 'source' => $run->source, 'external_id' => 'CRM-1',
    ]))->toThrow(QueryException::class);
});

test('notification intents are unique per activity recipient and channel', function () {
    $delivery = NotificationDelivery::factory()->create();

    expect(fn () => NotificationDelivery::factory()->create([
        'activity_entry_id' => $delivery->activity_entry_id,
        'recipient_id' => $delivery->recipient_id,
        'channel' => $delivery->channel,
    ]))->toThrow(QueryException::class);
});

test('command receipts cannot duplicate an operation key', function () {
    $receipt = CommandReceipt::factory()->create();

    expect(fn () => CommandReceipt::factory()->create(['operation_key' => $receipt->operation_key]))
        ->toThrow(QueryException::class);
});

test('default ticket configuration seeding is idempotent on mysql', function () {
    $this->seed(TicketingConfigurationSeeder::class);
    $this->seed(TicketingConfigurationSeeder::class);

    $settings = TicketingSetting::query()->findOrFail(1);

    expect($settings->current_sla_policy_id)->not->toBeNull()
        ->and(DB::table('calendar_windows')->count())->toBe(5)
        ->and(DB::table('sla_policy_targets')->count())->toBe(4);
});

test('attachments cannot cross ticket boundaries on mysql', function () {
    $message = TicketMessage::factory()->create();

    expect(fn () => TicketAttachment::factory()->create(['ticket_message_id' => $message->id]))
        ->toThrow(QueryException::class);
});

test('mysql rolls back ticket activity and delivery writes as one unit', function () {
    $before = Ticket::query()->count();

    expect(function (): void {
        DB::transaction(function (): void {
            $activity = ActivityEntry::factory()->create();
            NotificationDelivery::factory()->create(['activity_entry_id' => $activity->id]);

            throw new RuntimeException('Rollback test');
        });
    })->toThrow(RuntimeException::class);

    expect(Ticket::query()->count())->toBe($before);
    $this->assertDatabaseCount('activity_entries', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('an innodb row lock prevents another connection from overwriting the ticket', function () {
    $ticket = Ticket::factory()->create();
    DB::commit();
    DB::beginTransaction();
    Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
    config(['database.connections.mysql_competitor' => config('database.connections.mysql_schema_test')]);
    $competitor = DB::connection('mysql_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        expect(fn () => $competitor->table('tickets')->where('id', $ticket->id)->update([
            'subject' => 'Competing write',
        ]))->toThrow(QueryException::class);
        expect($ticket->fresh()->subject)->toBe($ticket->subject);
    } finally {
        $competitor->disconnect();
    }
});

test('application migrations can roll back and reapply on a disposable mysql schema', function () {
    DB::rollBack();

    expect(Artisan::call('migrate:rollback', ['--database' => 'mysql_schema_test', '--step' => 11, '--force' => true]))
        ->toBe(0, Artisan::output());
    expect(Schema::hasTable('tickets'))->toBeFalse()
        ->and(Schema::hasTable('access_roles'))->toBeFalse();

    expect(Artisan::call('migrate', ['--database' => 'mysql_schema_test', '--force' => true]))
        ->toBe(0, Artisan::output());
    $this->assertDatabaseHas('ticketing_settings', ['id' => 1, 'current_sla_policy_id' => null]);
});
