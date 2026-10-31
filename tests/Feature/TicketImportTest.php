<?php

use App\Enums\ImportStatus;
use App\Jobs\ProcessTicketImportChunk;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketExternalReference;
use App\Models\User;
use App\Services\Imports\CsvRecordReader;
use App\Services\Imports\TicketImportService;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use UnexpectedValueException;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->customer = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $this->customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    Storage::fake('local');
    Queue::fake();
});

it('renders the ticket import list and run detail pages', function (): void {
    $run = app(TicketImportService::class)->upload(
        $this->admin,
        'support-system',
        UploadedFile::fake()->createWithContent(
            'tickets.csv',
            "external_id,customer_email,subject,description,priority,category\n",
        ),
    );

    $this->actingAs($this->admin)
        ->get(route('dashboard.ticket-imports.index'))
        ->assertOk()
        ->assertSee('Ticket imports');

    $this->get(route('dashboard.ticket-imports.show', $run))
        ->assertOk()
        ->assertSee('Import run #'.$run->id);
});

it('renders success and error flash messages as dashboard toasts', function (): void {
    expect(config('tyro-dashboard.notifications.notification_style'))->toBe('toast');

    $this->actingAs($this->admin)
        ->withSession([
            'success' => 'Changes saved successfully.',
            'error' => 'The requested action failed.',
        ])
        ->get(route('dashboard.ticket-imports.index'))
        ->assertOk()
        ->assertSee('id="toast-container"', false)
        ->assertSee('class="toast toast-success"', false)
        ->assertSee('Changes saved successfully.')
        ->assertSee('class="toast toast-error"', false)
        ->assertSee('The requested action failed.');
});

it('streams quoted multiline rows and records created, duplicate, and invalid outcomes', function (): void {
    $csv = "\xEF\xBB\xBFexternal_id,customer_email,subject,description,priority,category\r\n"
        .'CRM-1,'.$this->customer->email.',"Subject, one","first line'."\r\n".'second line",high,general'."\r\n"
        .'CRM-1,'.$this->customer->email.',Duplicate,Body,high,general'."\r\n"
        .'CRM-2,missing@example.test,Invalid customer,Body,high,general'."\r\n";
    $file = UploadedFile::fake()->createWithContent('tickets.csv', $csv);

    $run = app(TicketImportService::class)->upload($this->admin, 'crm', $file);
    app(TicketImportService::class)->processChunk((int) $run->id, 0);

    expect($run->fresh()->processing_status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($run->fresh()->processed_rows)->toBe(3)
        ->and($run->fresh()->created_rows)->toBe(1)
        ->and($run->fresh()->duplicate_rows)->toBe(1)
        ->and($run->fresh()->invalid_rows)->toBe(1);
    expect(Ticket::query()->count())->toBe(1)
        ->and(Ticket::query()->firstOrFail()->subject)->toBe('Subject, one')
        ->and(Ticket::query()->firstOrFail()->description)->toBe("first line\r\nsecond line")
        ->and(TicketExternalReference::query()->where('source', 'crm')->where('external_id', 'CRM-1')->count())->toBe(1)
        ->and(ImportRow::query()->where('import_run_id', $run->id)->count())->toBe(3);

    app(TicketImportService::class)->processChunk((int) $run->id, 0);
    expect(Ticket::query()->count())->toBe(1);
});

it('pauses and resumes at a committed row checkpoint', function (): void {
    $csv = "external_id,customer_email,subject,description,priority,category\n"
        .'CRM-1,'.$this->customer->email.',One,First,high,general'."\n"
        .'CRM-2,'.$this->customer->email.',Two,Second,high,general'."\n";
    $run = app(TicketImportService::class)->upload($this->admin, 'crm', UploadedFile::fake()->createWithContent('tickets.csv', $csv));

    app(TicketImportService::class)->control($this->admin, (int) $run->id, 'pause');
    app(TicketImportService::class)->processChunk((int) $run->id, 0);
    expect(Ticket::query()->count())->toBe(0)
        ->and($run->fresh()->processing_status)->toBe(ImportStatus::Paused);

    app(TicketImportService::class)->control($this->admin, (int) $run->id, 'resume');
    app(TicketImportService::class)->processChunk((int) $run->id, 0);

    expect($run->fresh()->processing_status)->toBe(ImportStatus::Completed)
        ->and($run->fresh()->created_rows)->toBe(2)
        ->and(Ticket::query()->count())->toBe(2);
});

it('redispatches a stale import from its committed row checkpoint without duplicate tickets', function (): void {
    config(['ticketing.imports.chunk_rows' => 1]);
    $csv = "external_id,customer_email,subject,description,priority,category\n"
        .'CRM-1,'.$this->customer->email.',One,First,high,general'."\n"
        .'CRM-2,'.$this->customer->email.',Two,Second,high,general'."\n";
    $run = app(TicketImportService::class)->upload(
        $this->admin,
        'crm-recovery',
        UploadedFile::fake()->createWithContent('tickets.csv', $csv),
    );

    app(TicketImportService::class)->processChunk((int) $run->getKey(), 0);
    expect($run->fresh()->last_row_number)->toBe(2)
        ->and($run->fresh()->created_rows)->toBe(1)
        ->and(Ticket::query()->count())->toBe(1);

    $this->travel(11)->minutes();
    expect(app(TicketImportService::class)->dispatchStalled())->toBe(1);
    Queue::assertPushed(ProcessTicketImportChunk::class, fn (ProcessTicketImportChunk $job): bool => $job->importRunId === (int) $run->getKey() && $job->expectedLastRow === 2,
    );

    app(TicketImportService::class)->processChunk((int) $run->getKey(), 2);
    app(TicketImportService::class)->processChunk((int) $run->getKey(), 3);

    expect($run->fresh()->processing_status)->toBe(ImportStatus::Completed)
        ->and($run->fresh()->created_rows)->toBe(2)
        ->and(Ticket::query()->count())->toBe(2)
        ->and(ImportRow::query()->where('import_run_id', $run->getKey())->count())->toBe(2);
});

it('keeps source identities reserved when an imported ticket is archived', function (): void {
    $firstRun = app(TicketImportService::class)->upload(
        $this->admin,
        'legacy-crm',
        UploadedFile::fake()->createWithContent('first.csv', "external_id,customer_email,subject,description,priority,category\nLEGACY-17,{$this->customer->email},First,Imported,high,general\n"),
    );
    app(TicketImportService::class)->processChunk((int) $firstRun->id, 0);
    $ticket = Ticket::query()->firstOrFail();
    $ticket->delete();

    $secondRun = app(TicketImportService::class)->upload(
        $this->admin,
        'legacy-crm',
        UploadedFile::fake()->createWithContent('second.csv', "external_id,customer_email,subject,description,priority,category\nLEGACY-17,{$this->customer->email},Replay,Replay,high,general\n"),
    );
    app(TicketImportService::class)->processChunk((int) $secondRun->id, 0);

    expect($secondRun->fresh()->duplicate_rows)->toBe(1)
        ->and(Ticket::withTrashed()->count())->toBe(1)
        ->and(TicketExternalReference::query()->where('source', 'legacy-crm')->where('external_id', 'LEGACY-17')->count())->toBe(1);
});

it('fails a run with an invalid header without creating tickets', function (): void {
    $run = app(TicketImportService::class)->upload(
        $this->admin,
        'crm',
        UploadedFile::fake()->createWithContent('tickets.csv', "external_id,email,subject\nCRM-1,a@example.test,Subject\n"),
    );

    app(TicketImportService::class)->processChunk((int) $run->id, 0);

    expect($run->fresh()->processing_status)->toBe(ImportStatus::Failed)
        ->and($run->fresh()->failure_code)->toBe('invalid_csv_header')
        ->and(Ticket::query()->count())->toBe(0);
});

it('rejects a logical CSV record over the configured memory bound', function (): void {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, '"'.str_repeat('x', 40).'"'."\n");
    rewind($stream);
    $reader = new CsvRecordReader($stream, 0);

    expect(fn () => $reader->nextRecord(0, 32))->toThrow(UnexpectedValueException::class);

    fclose($stream);
});

it('does not allow a non-administrator to upload a ticket import', function (): void {
    $this->actingAs($this->customer)
        ->post(route('dashboard.ticket-imports.store'), [
            'source' => 'crm',
            'file' => UploadedFile::fake()->createWithContent('tickets.csv', "external_id,customer_email,subject,description,priority,category\n"),
        ])
        ->assertNotFound();

    expect(ImportRun::query()->count())->toBe(0);
});
