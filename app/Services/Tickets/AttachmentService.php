<?php

namespace App\Services\Tickets;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * @phpstan-type StagedTicketAttachment array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}
 */
class AttachmentService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly TicketActivityService $activity,
        private readonly TicketOperationService $operations,
    ) {}

    /**
     * Store uploads on the configured private disk before starting a database transaction.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}>
     */
    public function stage(array $files): array
    {
        if (count($files) > (int) config('ticketing.attachments.max_files', 5)) {
            throw ValidationException::withMessages(['attachments' => 'Attach no more than five files at a time.']);
        }

        $disk = $this->privateDisk();
        $staged = [];

        try {
            foreach ($files as $file) {
                if (! $file->isValid()) {
                    throw ValidationException::withMessages(['attachments' => 'One of the uploaded files could not be read.']);
                }

                $size = $file->getSize();
                $extension = strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType();
                $allowedMimeTypes = config('ticketing.attachments.allowed_types.'.$extension, []);
                if (! is_int($size)
                    || $size < 1
                    || $size > (int) config('ticketing.attachments.max_bytes', 10485760)
                    || ! is_string($mimeType)
                    || ! in_array($mimeType, $allowedMimeTypes, true)) {
                    throw ValidationException::withMessages([
                        'attachments' => 'Each file must be a supported PDF, JPEG, PNG, TXT or CSV file no larger than 10 MiB.',
                    ]);
                }

                $realPath = $file->getRealPath();
                $checksum = is_string($realPath) ? hash_file('sha256', $realPath) : false;
                if (! is_string($checksum)) {
                    throw new RuntimeException('The uploaded file checksum could not be calculated.');
                }

                $path = $file->store('ticket-files', $disk);
                if (! is_string($path)) {
                    throw new RuntimeException('The uploaded file could not be staged in private storage.');
                }

                $staged[] = [
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'sha256' => $checksum,
                ];
            }
        } catch (Throwable $exception) {
            $this->discard($staged);
            throw $exception;
        }

        return $staged;
    }

    /** @param list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}> $uploads */
    public function attachToTicket(Ticket $ticket, ?TicketMessage $message, User $actor, array $uploads): void
    {
        foreach ($uploads as $upload) {
            TicketAttachment::query()->forceCreate([
                'ticket_id' => $ticket->getKey(),
                'ticket_message_id' => $message?->getKey(),
                'uploaded_by' => $actor->getKey(),
                ...$upload,
                'is_active' => true,
            ]);
        }
    }

    /** @param list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}> $uploads
     * @return list<array{original_name: string, mime_type: string, size_bytes: int, sha256: string}>
     */
    public function fingerprint(array $uploads): array
    {
        return array_map(fn (array $upload): array => [
            'original_name' => $upload['original_name'],
            'mime_type' => $upload['mime_type'],
            'size_bytes' => $upload['size_bytes'],
            'sha256' => $upload['sha256'],
        ], $uploads);
    }

    /** @param list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}> $uploads */
    public function discard(array $uploads): void
    {
        foreach ($uploads as $upload) {
            try {
                if (str_starts_with($upload['path'], 'ticket-files/')) {
                    Storage::disk($upload['disk'])->delete($upload['path']);
                }
            } catch (Throwable $exception) {
                Log::warning('Unable to remove an uncommitted ticket attachment.', [
                    'path' => $upload['path'],
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function download(User $actor, int $attachmentId): StreamedResponse
    {
        $isAdministrator = in_array('admin', $actor->tyroRoleSlugs(), true);
        if (! $actor->is_active || $actor->trashed() || ! $this->permissions->allows($actor, 'tickets.view')) {
            throw new AuthorizationException;
        }

        $attachmentQuery = TicketAttachment::query();
        if ($isAdministrator) {
            $attachmentQuery->withTrashed();
        }
        $attachment = $attachmentQuery->whereKey($attachmentId)->firstOrFail();
        if ($attachment->trashed() || ! $attachment->is_active) {
            throw (new ModelNotFoundException)->setModel(TicketAttachment::class, [$attachmentId]);
        }

        $ticket = Ticket::withTrashed()->whereKey($attachment->ticket_id)->firstOrFail();
        if ($ticket->trashed()) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
        }

        $isCustomer = in_array('customer', $actor->tyroRoleSlugs(), true)
            && (int) $ticket->customer_id === (int) $actor->getKey()
            && $actor->email_verified_at !== null;
        $isAssignedAgent = in_array('agent', $actor->tyroRoleSlugs(), true)
            && (int) $ticket->assigned_agent_id === (int) $actor->getKey();
        if (! $isAdministrator && ! $isCustomer && ! $isAssignedAgent) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
        }
        if (! $ticket->is_active && ! $isAdministrator) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
        }

        if ($attachment->ticket_message_id !== null) {
            $message = TicketMessage::withTrashed()->whereKey($attachment->ticket_message_id)->firstOrFail();
            if ($message->trashed() || ! $message->is_active) {
                throw (new ModelNotFoundException)->setModel(TicketMessage::class, [$message->getKey()]);
            }
            if (MessageVisibility::tryFrom((string) $message->getRawOriginal('visibility')) === MessageVisibility::Internal
                && ! ($isAdministrator || $isAssignedAgent)) {
                throw (new ModelNotFoundException)->setModel(TicketMessage::class, [$message->getKey()]);
            }
        }

        $disk = $this->privateDisk();
        if ($attachment->disk !== $disk
            || ! str_starts_with($attachment->path, 'ticket-files/')
            || ! Storage::disk($disk)->exists($attachment->path)) {
            throw (new ModelNotFoundException)->setModel(TicketAttachment::class, [$attachmentId]);
        }

        $filename = Str::ascii($attachment->original_name);
        $filename = trim(preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?? '');
        if ($filename === '') {
            $filename = 'ticket-attachment';
        }

        return Storage::disk($disk)->download($attachment->path, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function moderate(
        User $actor,
        int $ticketId,
        int $attachmentId,
        string $action,
        string $reason,
        string $expectedUpdatedAt,
        string $operationKey,
    ): TicketAttachment {
        $payload = [
            'ticket_id' => $ticketId,
            'attachment_id' => $attachmentId,
            'action' => $action,
            'reason' => trim($reason),
            'expected_updated_at' => CarbonImmutable::parse($expectedUpdatedAt)->utc()->format('Y-m-d H:i:s.u'),
        ];

        return DB::transaction(function () use ($actor, $ticketId, $attachmentId, $action, $operationKey, $payload): TicketAttachment {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeAdministratorModeration($lockedActor);
            $attachment = TicketAttachment::withTrashed()
                ->where('ticket_id', $ticketId)
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->firstOrFail();

            $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.moderate_attachment', $operationKey, $payload);
            if ($receiptTicket !== null) {
                return $attachment;
            }
            if ($attachment->updated_at?->utc()->format('Y-m-d H:i:s.u') !== $payload['expected_updated_at']) {
                throw new HttpException(409, 'This attachment changed after the page loaded. Refresh and try again.');
            }

            $before = ['is_active' => $attachment->is_active, 'is_archived' => $attachment->trashed()];
            $this->applyModeration($attachment, $action, $lockedActor);
            $ticket->forceFill([
                'updated_by' => $lockedActor->getKey(),
                'lock_version' => $ticket->lock_version + 1,
            ])->save();
            $this->activity->record(
                $ticket,
                $lockedActor,
                ActorKind::User,
                'ticket.attachment_moderated',
                $operationKey,
                [
                    'attachment_id' => $attachment->getKey(),
                    'before' => $before,
                    'after' => ['is_active' => $attachment->is_active, 'is_archived' => $attachment->trashed()],
                    'reason' => $payload['reason'],
                ],
                MessageVisibility::Internal,
                TicketAttachment::class,
                (int) $attachment->getKey(),
            );
            $this->operations->complete($operationKey, $ticket, 'attachment_moderated');

            return TicketAttachment::withTrashed()->whereKey($attachment->getKey())->firstOrFail();
        }, attempts: 3);
    }

    private function privateDisk(): string
    {
        $disk = (string) config('ticketing.attachments.disk', 'local');
        if ($disk === '' || config('filesystems.disks.'.$disk.'.visibility') === 'public') {
            throw new LogicException('Ticket attachments require a configured private storage disk.');
        }

        return $disk;
    }

    private function authorizeAdministratorModeration(User $actor): void
    {
        if (! $actor->is_active || $actor->trashed()
            || ! in_array('admin', $actor->tyroRoleSlugs(), true)
            || ! $this->permissions->allows($actor, 'attachments.moderate')) {
            throw new AuthorizationException;
        }
    }

    private function applyModeration(TicketAttachment $attachment, string $action, User $actor): void
    {
        if (in_array($action, ['disable', 'enable'], true)) {
            $attachment->forceFill([
                'is_active' => $action === 'enable',
                'updated_by' => $actor->getKey(),
            ])->save();

            return;
        }
        if ($action === 'archive') {
            if ($attachment->trashed()) {
                throw ValidationException::withMessages(['action' => 'This attachment is already archived.']);
            }
            $attachment->forceFill(['deleted_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $attachment->delete();

            return;
        }
        if ($action === 'restore') {
            if (! $attachment->trashed()) {
                throw ValidationException::withMessages(['action' => 'Only an archived attachment can be restored.']);
            }
            $attachment->restore();
            $attachment->forceFill(['updated_by' => $actor->getKey()])->save();

            return;
        }

        throw ValidationException::withMessages(['action' => 'Choose a valid attachment moderation action.']);
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 255));

        return $name !== '' ? $name : 'attachment';
    }
}
