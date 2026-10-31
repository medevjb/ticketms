<?php

namespace App\Services\Tickets;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketSlaInterval;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** @phpstan-import-type StagedTicketAttachment from AttachmentService */
class ConversationService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly SlaService $sla,
        private readonly TicketActivityService $activity,
        private readonly TicketOperationService $operations,
        private readonly AttachmentService $attachments,
        private readonly TicketEscalationService $escalations,
    ) {}

    /**
     * @param  list<StagedTicketAttachment>  $stagedFiles
     */
    public function addMessage(
        User $actor,
        int $ticketId,
        string $body,
        MessageVisibility $visibility,
        int $expectedVersion,
        string $operationKey,
        bool $reopen,
        ?string $reason,
        array $stagedFiles,
    ): TicketMessage {
        $payload = [
            'ticket_id' => $ticketId,
            'body' => trim($body),
            'visibility' => $visibility->value,
            'lock_version' => $expectedVersion,
            'reopen' => $reopen,
            'reason' => $reason === null ? null : trim($reason),
            'attachments' => $this->attachments->fingerprint($stagedFiles),
        ];
        $retainStagedFiles = false;

        try {
            $message = DB::transaction(function () use ($actor, $ticketId, $visibility, $expectedVersion, $operationKey, $payload, $reopen, &$retainStagedFiles, $stagedFiles): TicketMessage {
                $retainStagedFiles = false;
                $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
                $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $this->authorizeMessage($lockedActor, $ticket, $visibility);

                $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.message', $operationKey, $payload);
                if ($receiptTicket !== null) {
                    return TicketMessage::withTrashed()->where('operation_key', $operationKey)->firstOrFail();
                }

                $this->assertVersion($ticket, $expectedVersion);
                if ($ticket->status === TicketStatus::Closed) {
                    throw ValidationException::withMessages(['body' => 'Closed tickets cannot receive messages.']);
                }

                if ($reopen) {
                    $this->reopenWithCustomerMessage($lockedActor, $ticket, $visibility, (string) $payload['reason']);
                } elseif ($ticket->status === TicketStatus::WaitingForCustomer
                    && $visibility === MessageVisibility::Public
                    && $this->isCustomerOwner($lockedActor, $ticket)) {
                    $this->resumeForCustomerReply($lockedActor, $ticket);
                } elseif ($ticket->status === TicketStatus::Resolved && $visibility === MessageVisibility::Public) {
                    throw ValidationException::withMessages(['reopen' => 'Reopen the ticket with a reason to send a public reply.']);
                }

                $message = $this->createMessageInTransaction(
                    $ticket,
                    $lockedActor,
                    $visibility,
                    $payload['body'],
                    $operationKey,
                    $stagedFiles,
                );
                $changes = [
                    'visibility' => $visibility->value,
                    'attachment_count' => count($stagedFiles),
                ];
                $activity = $this->activity->record(
                    $ticket,
                    $lockedActor,
                    ActorKind::User,
                    'ticket.message_posted',
                    $operationKey,
                    $changes,
                    $visibility,
                    TicketMessage::class,
                    (int) $message->getKey(),
                );

                if ($visibility === MessageVisibility::Public) {
                    $recipients = $this->publicReplyRecipients($ticket, $lockedActor);
                    $this->activity->queueDeliveryIntents(
                        $activity,
                        $ticket,
                        'ticket.message_posted',
                        $recipients,
                        "There is a new response on ticket {$ticket->number}.",
                    );
                }

                $ticket->forceFill([
                    'updated_by' => $lockedActor->getKey(),
                    'lock_version' => $ticket->lock_version + 1,
                ])->save();
                $this->operations->complete($operationKey, $ticket, 'message_posted');
                $retainStagedFiles = true;

                return $message->load('attachments');
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->attachments->discard($stagedFiles);
            throw $exception;
        }

        if (! $retainStagedFiles) {
            $this->attachments->discard($stagedFiles);
        }

        return $message;
    }

    /**
     * Create a public message as part of a ticket transition already owned by TicketService.
     *
     * @param  list<StagedTicketAttachment>  $stagedFiles
     */
    public function createTransitionMessageInTransaction(
        Ticket $ticket,
        User $author,
        string $body,
        string $operationKey,
        array $stagedFiles,
    ): TicketMessage {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Transition messages must be created inside the ticket transaction.');
        }
        $this->authorizeMessage($author, $ticket, MessageVisibility::Public, true);

        return $this->createMessageInTransaction(
            $ticket,
            $author,
            MessageVisibility::Public,
            $body,
            $operationKey,
            $stagedFiles,
        );
    }

    /**
     * @return Collection<int, TicketMessage>
     */
    public function messagesFor(User $actor, Ticket $ticket): Collection
    {
        $this->assertCanViewTicket($actor, $ticket);
        $isAdministrator = $this->isAdministrator($actor);
        $isStaff = $isAdministrator || $this->isAssignedAgent($actor, $ticket);

        $query = $ticket->messages()->with('author')->orderBy('created_at')->orderBy('id');
        if ($isAdministrator) {
            $query->withTrashed();
        }
        if (! $isStaff) {
            $query->where('visibility', MessageVisibility::Public);
        }

        return $query->with(['attachments' => function (HasMany $attachments) use ($isAdministrator): void {
            if ($isAdministrator) {
                $attachments->withTrashed();
            }
            if (! $isAdministrator) {
                $attachments->where('is_active', true);
            }
        }])->get();
    }

    public function canReply(User $actor, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::Closed || $ticket->trashed()) {
            return false;
        }
        if ($ticket->status === TicketStatus::Resolved
            && ! $this->isAdministrator($actor)
            && ! $this->isCustomerOwner($actor, $ticket)) {
            return false;
        }
        if ($this->isCustomerOwner($actor, $ticket)) {
            return $ticket->is_active
                && $actor->email_verified_at !== null
                && $this->permissions->allows($actor, 'tickets.reply_public');
        }

        return $ticket->is_active
            && $this->isAssignedAgent($actor, $ticket)
            && $this->permissions->allows($actor, 'tickets.reply_public');
    }

    public function canAddInternalNote(User $actor, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::Closed || $ticket->trashed()) {
            return false;
        }
        if ($this->isAdministrator($actor)) {
            return $this->permissions->allows($actor, 'tickets.note_internal');
        }

        return $ticket->is_active
            && $this->isAssignedAgent($actor, $ticket)
            && $this->permissions->allows($actor, 'tickets.note_internal');
    }

    public function moderateMessage(
        User $actor,
        int $ticketId,
        int $messageId,
        string $action,
        string $reason,
        string $expectedUpdatedAt,
        string $operationKey,
    ): TicketMessage {
        $payload = [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'action' => $action,
            'reason' => trim($reason),
            'expected_updated_at' => CarbonImmutable::parse($expectedUpdatedAt)->utc()->format('Y-m-d H:i:s.u'),
        ];

        return DB::transaction(function () use ($actor, $ticketId, $messageId, $action, $operationKey, $payload): TicketMessage {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeAdministratorModeration($lockedActor, 'messages.moderate');
            $message = TicketMessage::withTrashed()->where('ticket_id', $ticketId)->whereKey($messageId)->lockForUpdate()->firstOrFail();

            $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.moderate_message', $operationKey, $payload);
            if ($receiptTicket !== null) {
                return $message;
            }
            $this->assertUpdatedAt($message, $payload['expected_updated_at']);

            $before = ['is_active' => $message->is_active, 'is_archived' => $message->trashed()];
            $this->applyMessageModeration($message, $action, $lockedActor);
            $ticket->forceFill([
                'updated_by' => $lockedActor->getKey(),
                'lock_version' => $ticket->lock_version + 1,
            ])->save();

            $this->activity->record(
                $ticket,
                $lockedActor,
                ActorKind::User,
                'ticket.message_moderated',
                $operationKey,
                [
                    'message_id' => $message->getKey(),
                    'before' => $before,
                    'after' => ['is_active' => $message->is_active, 'is_archived' => $message->trashed()],
                    'reason' => $payload['reason'],
                ],
                MessageVisibility::Internal,
                TicketMessage::class,
                (int) $message->getKey(),
            );
            $this->operations->complete($operationKey, $ticket, 'message_moderated');

            return TicketMessage::withTrashed()->whereKey($message->getKey())->firstOrFail();
        }, attempts: 3);
    }

    /** @param list<StagedTicketAttachment> $stagedFiles */
    private function createMessageInTransaction(
        Ticket $ticket,
        User $author,
        MessageVisibility $visibility,
        string $body,
        string $operationKey,
        array $stagedFiles,
    ): TicketMessage {
        $message = TicketMessage::query()->forceCreate([
            'ticket_id' => $ticket->getKey(),
            'author_id' => $author->getKey(),
            'visibility' => $visibility,
            'body' => trim($body),
            'operation_key' => $operationKey,
            'is_active' => true,
        ]);
        $this->attachments->attachToTicket($ticket, $message, $author, $stagedFiles);

        return $message;
    }

    private function authorizeMessage(User $actor, Ticket $ticket, MessageVisibility $visibility, bool $isTransitionMessage = false): void
    {
        $this->assertCanViewTicket($actor, $ticket);
        if ($ticket->status === TicketStatus::Closed) {
            throw ValidationException::withMessages(['body' => 'Closed tickets cannot receive messages.']);
        }
        if (! $ticket->is_active && ! $this->isAdministrator($actor)) {
            throw new AuthorizationException;
        }

        if ($visibility === MessageVisibility::Internal) {
            if (! $this->permissions->allows($actor, 'tickets.note_internal')
                || ! ($this->isAdministrator($actor) || $this->isAssignedAgent($actor, $ticket))) {
                throw new AuthorizationException;
            }

            return;
        }

        if (($this->isAdministrator($actor) && ! $isTransitionMessage)
            || ! $this->permissions->allows($actor, 'tickets.reply_public')
            || ! ($this->isAdministrator($actor) || $this->isCustomerOwner($actor, $ticket) || $this->isAssignedAgent($actor, $ticket))) {
            throw new AuthorizationException;
        }
        if ($this->isCustomer($actor) && $actor->email_verified_at === null) {
            throw new AuthorizationException;
        }
    }

    private function assertCanViewTicket(User $actor, Ticket $ticket): void
    {
        if ($ticket->trashed() || ! $this->permissions->allows($actor, 'tickets.view')) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
        }

        $authorized = $this->isAdministrator($actor)
            || $this->isCustomerOwner($actor, $ticket)
            || $this->isAssignedAgent($actor, $ticket);
        if (! $authorized) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
        }
    }

    private function reopenWithCustomerMessage(User $actor, Ticket $ticket, MessageVisibility $visibility, string $reason): void
    {
        if ($ticket->status !== TicketStatus::Resolved || $visibility !== MessageVisibility::Public
            || ! ($this->isAdministrator($actor) || $this->isCustomerOwner($actor, $ticket))
            || ! $this->permissions->allows($actor, 'tickets.reopen')) {
            throw ValidationException::withMessages(['reopen' => 'Only the ticket owner or an administrator can reopen a resolved ticket with a public reply.']);
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen(trim($reason)) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a reopening reason between 5 and 500 characters.']);
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $consumed = $this->sla->consumedSeconds($ticket, $now);
        $this->closeOpenInterval($ticket, $now, $actor);
        $projection = $this->sla->activeProjection($ticket, $now);
        $ticket->forceFill([
            'status' => TicketStatus::InProgress,
            'resolved_at' => null,
            'closed_at' => null,
            'resolution_cycle' => $ticket->resolution_cycle + 1,
            'sla_paused_at' => null,
            'sla_consumed_seconds' => $consumed,
            'sla_warning_at' => $projection['warning_at'],
            'sla_due_at' => $projection['due_at'],
            'updated_by' => $actor->getKey(),
            'lock_version' => $ticket->lock_version + 1,
        ])->save();
        $this->escalations->recordCurrentStageInTransaction($ticket, $now);

        $this->activity->record(
            $ticket,
            $actor,
            ActorKind::User,
            'ticket.reopened',
            (string) Str::uuid(),
            ['from' => TicketStatus::Resolved->value, 'to' => TicketStatus::InProgress->value, 'reason' => trim($reason)],
            MessageVisibility::Public,
        );
    }

    private function resumeForCustomerReply(User $actor, Ticket $ticket): void
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $consumed = $this->sla->consumedSeconds($ticket, $now);
        $this->closeOpenInterval($ticket, $now, $actor);
        $projection = $this->sla->activeProjection($ticket, $now);
        $ticket->forceFill([
            'status' => TicketStatus::InProgress,
            'sla_paused_at' => null,
            'sla_consumed_seconds' => $consumed,
            'sla_warning_at' => $projection['warning_at'],
            'sla_due_at' => $projection['due_at'],
            'updated_by' => $actor->getKey(),
            'lock_version' => $ticket->lock_version + 1,
        ])->save();
        $this->activity->record(
            $ticket,
            $actor,
            ActorKind::User,
            'ticket.resumed_by_customer_reply',
            (string) Str::uuid(),
            ['from' => TicketStatus::WaitingForCustomer->value, 'to' => TicketStatus::InProgress->value],
            MessageVisibility::Public,
        );
    }

    private function closeOpenInterval(Ticket $ticket, CarbonImmutable $endedAt, User $actor): void
    {
        $interval = TicketSlaInterval::query()
            ->where('ticket_id', $ticket->getKey())
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();
        if ($interval !== null) {
            $interval->forceFill(['ended_at' => $endedAt, 'ended_by' => $actor->getKey()])->save();
        }
    }

    /** @return list<int> */
    private function publicReplyRecipients(Ticket $ticket, User $actor): array
    {
        $recipients = $this->isCustomer($actor)
            ? ($ticket->assigned_agent_id === null ? $this->activity->activeAdministratorIds() : [(int) $ticket->assigned_agent_id])
            : [(int) $ticket->customer_id];

        return array_values(array_filter(array_unique($recipients), fn (int $id): bool => $id !== (int) $actor->getKey()));
    }

    private function applyMessageModeration(TicketMessage $message, string $action, User $actor): void
    {
        if ($action === 'hide' || $action === 'unhide') {
            $message->forceFill([
                'is_active' => $action === 'unhide',
                'updated_by' => $actor->getKey(),
            ])->save();

            return;
        }
        if ($action === 'archive') {
            if ($message->trashed()) {
                throw ValidationException::withMessages(['action' => 'This message is already archived.']);
            }
            $message->forceFill(['deleted_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $message->delete();

            return;
        }
        if ($action === 'restore') {
            if (! $message->trashed()) {
                throw ValidationException::withMessages(['action' => 'Only an archived message can be restored.']);
            }
            $message->restore();
            $message->forceFill(['updated_by' => $actor->getKey()])->save();

            return;
        }

        throw ValidationException::withMessages(['action' => 'Choose a valid message moderation action.']);
    }

    private function assertUpdatedAt(TicketMessage $message, string $expectedUpdatedAt): void
    {
        if ($message->updated_at?->utc()->format('Y-m-d H:i:s.u') !== $expectedUpdatedAt) {
            throw new HttpException(409, 'This message changed after the page loaded. Refresh and try again.');
        }
    }

    private function authorizeAdministratorModeration(User $actor, string $permission): void
    {
        if (! $actor->is_active || $actor->trashed() || ! $this->isAdministrator($actor) || ! $this->permissions->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function isAdministrator(User $actor): bool
    {
        return in_array('admin', $actor->tyroRoleSlugs(), true);
    }

    private function isCustomer(User $actor): bool
    {
        return in_array('customer', $actor->tyroRoleSlugs(), true);
    }

    private function isCustomerOwner(User $actor, Ticket $ticket): bool
    {
        return $this->isCustomer($actor) && (int) $ticket->customer_id === (int) $actor->getKey();
    }

    private function isAssignedAgent(User $actor, Ticket $ticket): bool
    {
        return in_array('agent', $actor->tyroRoleSlugs(), true)
            && (int) $ticket->assigned_agent_id === (int) $actor->getKey();
    }

    private function assertVersion(Ticket $ticket, int $expectedVersion): void
    {
        if ((int) $ticket->lock_version !== $expectedVersion) {
            throw new HttpException(409, 'This ticket changed after the page loaded. Refresh and try again.');
        }
    }
}
