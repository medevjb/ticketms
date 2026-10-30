<?php

namespace App\Services\Imports;

use App\Enums\ActorKind;
use App\Enums\ImportRowResult;
use App\Enums\ImportStatus;
use App\Enums\MessageVisibility;
use App\Jobs\ProcessTicketImportChunk;
use App\Models\ActivityEntry;
use App\Models\Category;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\Priority;
use App\Models\TicketExternalReference;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnexpectedValueException;

class TicketImportService
{
    private const REQUIRED_HEADERS = ['external_id', 'customer_email', 'subject', 'description', 'priority', 'category'];

    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly TicketService $tickets,
    ) {}

    /** @return LengthAwarePaginator<int, ImportRun> */
    public function paginate(User $actor, string $status = 'all'): LengthAwarePaginator
    {
        $this->authorize($actor, 'imports.manage');
        $query = ImportRun::query()->with('uploader:id,name,email')->latest('id');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status !== 'all') {
            $query->where('processing_status', $status);
        } else {
            $query->withTrashed();
        }

        return $query->paginate(20);
    }

    public function find(User $actor, int $runId): ImportRun
    {
        $this->authorize($actor, 'imports.manage');

        return ImportRun::withTrashed()->with(['uploader:id,name,email'])->whereKey($runId)->firstOrFail();
    }

    public function upload(User $actor, string $source, UploadedFile $file): ImportRun
    {
        $this->authorize($actor, 'imports.manage');
        if ($file->getSize() > 100 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'The CSV file cannot exceed 100 MiB.']);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs('ticket-imports', Str::uuid().'.csv', 'local');
        if ($path === false) {
            throw new RuntimeException('The import file could not be stored.');
        }

        try {
            $run = DB::transaction(function () use ($actor, $source, $path, $checksum): ImportRun {
                $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $this->authorize($lockedActor, 'imports.manage');
                $run = ImportRun::query()->forceCreate([
                    'source' => trim($source),
                    'uploaded_by' => $lockedActor->getKey(),
                    'disk' => 'local',
                    'path' => $path,
                    'checksum' => $checksum,
                    'processing_status' => ImportStatus::Queued,
                    'processed_rows' => 0,
                    'created_rows' => 0,
                    'invalid_rows' => 0,
                    'duplicate_rows' => 0,
                    'last_row_number' => 0,
                    'last_byte_offset' => 0,
                    'is_active' => true,
                ]);
                $this->recordActivity($run, $lockedActor, 'ticket_import.queued', ['source' => $run->source, 'checksum' => $checksum]);

                return $run;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        ProcessTicketImportChunk::dispatch((int) $run->getKey(), 0)
            ->onQueue((string) config('ticketing.imports.queue', 'imports'))
            ->afterCommit();

        return $run;
    }

    public function processChunk(int $runId, int $expectedLastRow): void
    {
        $run = ImportRun::query()->whereKey($runId)->first();
        if ($run === null || in_array($run->processing_status, [ImportStatus::Paused, ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Failed, ImportStatus::Cancelled], true)) {
            return;
        }

        $actor = User::query()->active()->whereKey($run->uploaded_by)->first();
        if ($actor === null || ! $this->isAdministrator($actor) || ! $this->permissions->allows($actor, 'imports.manage')) {
            $this->failRun($runId, 'uploader_no_longer_authorized');

            return;
        }

        if ((int) $run->last_row_number !== $expectedLastRow) {
            return;
        }

        $stream = Storage::disk($run->disk)->readStream($run->path);
        if (! is_resource($stream)) {
            $this->failRun($runId, 'source_file_missing');

            return;
        }

        $startOffset = (int) $run->last_byte_offset;
        $reader = new CsvRecordReader($stream, $startOffset);
        $parseFailureCode = null;
        $records = [];
        try {
            if ((int) $run->last_row_number === 0) {
                $headerRecord = $reader->nextRecord(0, (int) config('ticketing.imports.max_record_bytes', 512000));
                $headers = $headerRecord === null ? [] : $this->parseRecord($headerRecord['record']);
                if (isset($headers[0])) {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
                }
                if ($headers !== self::REQUIRED_HEADERS) {
                    fclose($stream);
                    $this->failRun($runId, 'invalid_csv_header');

                    return;
                }
                $startOffset = $headerRecord['next_offset'];
                $reader = new CsvRecordReader($stream, $startOffset);
                DB::transaction(function () use ($runId, $startOffset): void {
                    $locked = ImportRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                    if ($locked->processing_status === ImportStatus::Queued) {
                        $locked->forceFill([
                            'processing_status' => ImportStatus::Running,
                            'started_at' => CarbonImmutable::now('UTC'),
                            'last_row_number' => 1,
                            'last_byte_offset' => $startOffset,
                        ])->save();
                    }
                }, attempts: 3);
            } elseif ($run->processing_status === ImportStatus::Queued) {
                DB::transaction(function () use ($runId): void {
                    $queued = ImportRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                    if ($queued->processing_status === ImportStatus::Queued) {
                        $queued->forceFill([
                            'processing_status' => ImportStatus::Running,
                            'started_at' => $queued->started_at ?? CarbonImmutable::now('UTC'),
                        ])->save();
                    }
                }, attempts: 3);
            }

            $recordOffset = $startOffset;
            for ($index = 0; $index < (int) config('ticketing.imports.chunk_rows', 100); $index++) {
                $record = $reader->nextRecord($recordOffset, (int) config('ticketing.imports.max_record_bytes', 512000));
                if ($record === null) {
                    break;
                }
                $recordOffset = $record['next_offset'];
                $records[] = ['record' => $record['record'], 'offset' => $recordOffset];
            }
        } catch (UnexpectedValueException $exception) {
            $parseFailureCode = $exception->getMessage() === 'A CSV record exceeds the maximum permitted size.' ? 'record_too_large' : 'malformed_csv';
        }
        fclose($stream);

        $lastRow = max((int) $run->last_row_number, 1);
        foreach ($records as $record) {
            $lastRow++;
            try {
                $fields = $this->parseRecord($record['record']);
                $this->processRecord($runId, $actor, $lastRow, $record['offset'], $fields);
            } catch (UnexpectedValueException) {
                $this->persistResult($runId, $lastRow, $record['offset'], null, ImportRowResult::Invalid, ['invalid_encoding_or_csv']);
            }

            $current = ImportRun::query()->whereKey($runId)->first();
            if ($current === null || $current->processing_status !== ImportStatus::Running) {
                return;
            }
        }

        $current = ImportRun::query()->whereKey($runId)->firstOrFail();
        if ($parseFailureCode !== null) {
            $this->failRun($runId, $parseFailureCode);

            return;
        }
        if (count($records) < (int) config('ticketing.imports.chunk_rows', 100)) {
            $this->completeRun($runId);

            return;
        }

        ProcessTicketImportChunk::dispatch($runId, (int) $current->last_row_number)
            ->onQueue((string) config('ticketing.imports.queue', 'imports'))
            ->afterCommit();
    }

    public function dispatchStalled(int $limit = 100): int
    {
        $cutoff = CarbonImmutable::now('UTC')->subMinutes((int) config('ticketing.imports.stale_after_minutes', 10));
        $runs = ImportRun::query()
            ->whereIn('processing_status', [ImportStatus::Queued->value, ImportStatus::Running->value])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')->orderBy('id')->limit(min(500, max(1, $limit)))
            ->get(['id', 'last_row_number']);

        foreach ($runs as $run) {
            ProcessTicketImportChunk::dispatch((int) $run->getKey(), (int) $run->last_row_number)
                ->onQueue((string) config('ticketing.imports.queue', 'imports'));
        }

        return $runs->count();
    }

    public function control(User $actor, int $runId, string $action): ImportRun
    {
        $this->authorize($actor, 'imports.manage');

        $run = DB::transaction(function () use ($actor, $runId, $action): ImportRun {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, 'imports.manage');
            $runQuery = ImportRun::withTrashed()->whereKey($runId)->lockForUpdate();
            $run = $runQuery->firstOrFail();
            if ($run->trashed() && $action !== 'restore') {
                throw ValidationException::withMessages(['action' => 'Restore this import run before changing it.']);
            }
            $allowed = match ($action) {
                'pause' => in_array($run->processing_status, [ImportStatus::Queued, ImportStatus::Running], true),
                'resume' => $run->processing_status === ImportStatus::Paused,
                'cancel' => in_array($run->processing_status, [ImportStatus::Queued, ImportStatus::Running, ImportStatus::Paused], true),
                'archive' => in_array($run->processing_status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Failed, ImportStatus::Cancelled], true),
                'restore' => $run->trashed(),
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['action' => 'This action is not available for the import run in its current state.']);
            }

            if ($action === 'restore') {
                $run->restore();
                $run->forceFill(['is_active' => true, 'updated_by' => $lockedActor->getKey(), 'deleted_by' => null])->save();
            } elseif ($action === 'archive') {
                $run->forceFill(['is_active' => false, 'updated_by' => $lockedActor->getKey(), 'deleted_by' => $lockedActor->getKey()])->save();
                $run->delete();
            } else {
                $status = match ($action) {
                    'pause' => ImportStatus::Paused,
                    'resume' => ImportStatus::Queued,
                    'cancel' => ImportStatus::Cancelled,
                };
                $run->forceFill([
                    'processing_status' => $status,
                    'is_active' => $action === 'resume',
                    'updated_by' => $lockedActor->getKey(),
                    'completed_at' => $action === 'cancel' ? CarbonImmutable::now('UTC') : $run->completed_at,
                ])->save();
            }
            $this->recordActivity($run, $lockedActor, 'ticket_import.'.$action, ['status' => $run->processing_status->value]);

            return $run;
        }, attempts: 3);

        if ($action === 'resume') {
            ProcessTicketImportChunk::dispatch((int) $run->getKey(), (int) $run->last_row_number)
                ->onQueue((string) config('ticketing.imports.queue', 'imports'))
                ->afterCommit();
        }

        return $run;
    }

    public function errorReport(User $actor, int $runId): StreamedResponse
    {
        $this->authorize($actor, 'imports.download_errors');
        $run = ImportRun::withTrashed()->whereKey($runId)->firstOrFail();
        $filename = 'ticket-import-'.$run->getKey().'-errors.csv';

        return response()->streamDownload(function () use ($run): void {
            $stream = fopen('php://output', 'wb');
            if (! is_resource($stream)) {
                return;
            }
            fputcsv($stream, ['row_number', 'external_id', 'error_codes'], ',', '"', '');
            ImportRow::query()->where('import_run_id', $run->getKey())->where('result', ImportRowResult::Invalid)
                ->orderBy('row_number')->chunkById(500, function ($rows) use ($stream): void {
                    foreach ($rows as $row) {
                        fputcsv($stream, [
                            $row->row_number,
                            $this->neutralizeCsvFormula((string) $row->external_id),
                            implode(';', $row->error_codes ?? []),
                        ], ',', '"', '');
                    }
                });
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function failRun(int $runId, string $failureCode): void
    {
        DB::transaction(function () use ($runId, $failureCode): void {
            $run = ImportRun::query()->whereKey($runId)->lockForUpdate()->first();
            if ($run === null || in_array($run->processing_status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Cancelled], true)) {
                return;
            }
            $run->forceFill([
                'processing_status' => ImportStatus::Failed,
                'failure_code' => Str::limit($failureCode, 80, ''),
                'completed_at' => CarbonImmutable::now('UTC'),
            ])->save();
        }, attempts: 3);
    }

    /** @param list<string> $fields */
    private function processRecord(int $runId, User $actor, int $rowNumber, int $offset, array $fields): void
    {
        if (count($fields) !== count(self::REQUIRED_HEADERS)) {
            $this->persistResult($runId, $rowNumber, $offset, null, ImportRowResult::Invalid, ['column_count']);

            return;
        }
        $row = array_combine(self::REQUIRED_HEADERS, array_map('trim', $fields));
        $row['customer_email'] = mb_strtolower($row['customer_email']);
        $row['priority'] = mb_strtolower($row['priority']);
        $row['category'] = mb_strtolower($row['category']);
        $errors = [];
        foreach (['external_id' => 128, 'customer_email' => 254, 'subject' => 255, 'description' => 20000, 'priority' => 30, 'category' => 100] as $field => $max) {
            if ($row[$field] === '') {
                $errors[] = $field.'_required';
            } elseif (mb_strlen($row[$field]) > $max) {
                $errors[] = $field.'_too_long';
            }
        }
        if ($row['customer_email'] !== '' && filter_var($row['customer_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'customer_email_invalid';
        }
        if ($errors !== []) {
            $this->persistResult($runId, $rowNumber, $offset, $row['external_id'], ImportRowResult::Invalid, $errors);

            return;
        }

        $customer = User::query()->active()->whereNull('deleted_at')->whereNotNull('email_verified_at')->where('email', $row['customer_email'])->first();
        $category = Category::query()->active()->where('slug', $row['category'])->first();
        $priority = Priority::query()->active()->where('slug', $row['priority'])->first();
        if ($customer === null || ! $customer->hasRole('customer')) {
            $errors[] = 'customer_not_found_or_unverified';
        }
        if ($category === null) {
            $errors[] = 'category_unavailable';
        }
        if ($priority === null) {
            $errors[] = 'priority_unavailable';
        }
        if ($errors !== []) {
            $this->persistResult($runId, $rowNumber, $offset, $row['external_id'], ImportRowResult::Invalid, $errors);

            return;
        }

        $run = ImportRun::query()->findOrFail($runId);
        if (TicketExternalReference::query()->where('source', $run->source)->where('external_id', $row['external_id'])->exists()) {
            $this->persistResult($runId, $rowNumber, $offset, $row['external_id'], ImportRowResult::Duplicate, []);

            return;
        }

        try {
            DB::transaction(function () use ($runId, $actor, $rowNumber, $offset, $row, $customer, $category, $priority): void {
                $run = ImportRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                if ($run->processing_status !== ImportStatus::Running || (int) $run->last_row_number !== $rowNumber - 1) {
                    return;
                }
                $externalReference = [
                    'source' => $run->source,
                    'external_id' => $row['external_id'],
                    'import_run_id' => (int) $run->getKey(),
                ];
                $operationKey = $this->rowOperationKey($runId, $rowNumber);
                $ticket = $this->tickets->create($actor, [
                    'customer_id' => (int) $customer->getKey(),
                    'category_id' => (int) $category->getKey(),
                    'priority_id' => (int) $priority->getKey(),
                    'subject' => $row['subject'],
                    'description' => $row['description'],
                ], $operationKey, [], $externalReference);
                ImportRow::query()->forceCreate([
                    'import_run_id' => $run->getKey(),
                    'row_number' => $rowNumber,
                    'external_id' => $row['external_id'],
                    'result' => ImportRowResult::Created,
                    'ticket_id' => $ticket->getKey(),
                    'error_codes' => null,
                    'processed_at' => CarbonImmutable::now('UTC'),
                ]);
                $run->forceFill([
                    'processed_rows' => $run->processed_rows + 1,
                    'created_rows' => $run->created_rows + 1,
                    'last_row_number' => $rowNumber,
                    'last_byte_offset' => $offset,
                ])->save();
            }, attempts: 3);
        } catch (ValidationException) {
            $this->persistResult($runId, $rowNumber, $offset, $row['external_id'], ImportRowResult::Invalid, ['dependency_changed']);
        } catch (QueryException $exception) {
            if (! DB::table('ticket_external_references')->where('source', $run->source)->where('external_id', $row['external_id'])->exists()) {
                throw $exception;
            }
            $this->persistResult($runId, $rowNumber, $offset, $row['external_id'], ImportRowResult::Duplicate, []);
        }
    }

    /** @param list<string> $errorCodes */
    private function persistResult(int $runId, int $rowNumber, int $offset, ?string $externalId, ImportRowResult $result, array $errorCodes): void
    {
        DB::transaction(function () use ($runId, $rowNumber, $offset, $externalId, $result, $errorCodes): void {
            $run = ImportRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ($run->processing_status !== ImportStatus::Running || (int) $run->last_row_number !== $rowNumber - 1) {
                return;
            }
            ImportRow::query()->forceCreate([
                'import_run_id' => $run->getKey(),
                'row_number' => $rowNumber,
                'external_id' => $externalId === '' ? null : $externalId,
                'result' => $result,
                'ticket_id' => null,
                'error_codes' => $errorCodes === [] ? null : $errorCodes,
                'processed_at' => CarbonImmutable::now('UTC'),
            ]);
            $run->forceFill([
                'processed_rows' => $run->processed_rows + 1,
                'invalid_rows' => $run->invalid_rows + ($result === ImportRowResult::Invalid ? 1 : 0),
                'duplicate_rows' => $run->duplicate_rows + ($result === ImportRowResult::Duplicate ? 1 : 0),
                'last_row_number' => $rowNumber,
                'last_byte_offset' => $offset,
            ])->save();
        }, attempts: 3);
    }

    private function completeRun(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = ImportRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ($run->processing_status !== ImportStatus::Running) {
                return;
            }
            $run->forceFill([
                'processing_status' => $run->invalid_rows > 0 || $run->duplicate_rows > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
                'completed_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->recordActivity($run, User::query()->findOrFail($run->uploaded_by), 'ticket_import.completed', [
                'processed_rows' => $run->processed_rows,
                'created_rows' => $run->created_rows,
                'invalid_rows' => $run->invalid_rows,
                'duplicate_rows' => $run->duplicate_rows,
            ]);
        }, attempts: 3);
    }

    /** @return list<string> */
    private function parseRecord(string $record): array
    {
        if (! mb_check_encoding($record, 'UTF-8') || str_contains($record, "\0")) {
            throw new UnexpectedValueException('A CSV record contains invalid encoding.');
        }
        $fields = str_getcsv($record, ',', '"', '');

        return array_map(static fn (mixed $value): string => (string) $value, $fields);
    }

    private function rowOperationKey(int $runId, int $rowNumber): string
    {
        $hex = substr(hash('sha256', 'ticket-import:'.$runId.':'.$rowNumber), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $this->isAdministrator($actor) || ! $this->permissions->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function isAdministrator(User $actor): bool
    {
        return in_array('admin', $actor->tyroRoleSlugs(), true);
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(ImportRun $run, User $actor, string $action, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => $action,
            'visibility' => MessageVisibility::Internal,
            'subject_type' => ImportRun::class,
            'subject_id' => $run->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }

    private function neutralizeCsvFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
