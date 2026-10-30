<?php

namespace App\Services\Tickets;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Enums\PublicationState;
use App\Enums\TicketStatus;
use App\Enums\TicketTransition;
use App\Models\CalendarVersion;
use App\Models\Category;
use App\Models\Priority;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use App\Models\Ticket;
use App\Models\TicketExternalReference;
use App\Models\TicketingSetting;
use App\Models\TicketSlaInterval;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** @phpstan-import-type StagedTicketAttachment from AttachmentService */
class TicketService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly SlaService $sla,
        private readonly AssignmentService $assignments,
        private readonly TicketActivityService $activity,
        private readonly TicketOperationService $operations,
        private readonly ConversationService $conversations,
        private readonly AttachmentService $attachments,
        private readonly TicketEscalationService $escalations,
    ) {}

    /** @return array{categories: Collection<int, Category>, priorities: Collection<int, Priority>, customers: Collection<int, User>} */
    public function creationOptions(User $actor): array
    {
        $this->authorize($actor, 'tickets.create');
        if (! $this->isAdministrator($actor) && ! $this->isCustomer($actor)) {
            throw new AuthorizationException;
        }
        $this->ensureVerifiedCustomer($actor);

        $customers = $this->isAdministrator($actor)
            ? User::query()->active()->whereNotNull('email_verified_at')->whereHas('roles', function (Builder $query): void {
                $query->where('name', 'customer')->where('is_active', true)->whereNull('deleted_at');
            })->orderBy('name')->orderBy('id')->get(['id', 'name', 'email'])
            : new Collection;

        return [
            'categories' => Category::query()->active()->orderBy('name')->orderBy('id')->get(),
            'priorities' => Priority::query()->active()->orderBy('sort_order')->orderBy('id')->get(),
            'customers' => $customers,
        ];
    }

    /**
     * @param  array{customer_id?: int|null, category_id: int, priority_id: int, subject: string, description: string}  $data
     * @param  list<StagedTicketAttachment>  $stagedFiles
     * @param  list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}>  $stagedFiles
     * @param  array{source: string, external_id: string, import_run_id: int}|null  $externalReference
     */
    public function create(User $actor, array $data, string $operationKey, array $stagedFiles = [], ?array $externalReference = null): Ticket
    {
        try {
            $this->authorize($actor, 'tickets.create');
            if (! $this->isAdministrator($actor) && ! $this->isCustomer($actor)) {
                throw new AuthorizationException;
            }
            $this->ensureVerifiedCustomer($actor);
        } catch (Throwable $exception) {
            $this->attachments->discard($stagedFiles);
            throw $exception;
        }

        $payload = [
            'customer_id' => $this->isAdministrator($actor) ? (int) ($data['customer_id'] ?? 0) : (int) $actor->getKey(),
            'category_id' => (int) $data['category_id'],
            'priority_id' => (int) $data['priority_id'],
            'subject' => trim($data['subject']),
            'description' => trim($data['description']),
            'attachments' => $this->attachments->fingerprint($stagedFiles),
            'external_reference' => $externalReference,
        ];
        $retainStagedFiles = false;

        try {
            $ticket = DB::transaction(function () use ($actor, $payload, $operationKey, $stagedFiles, $externalReference, &$retainStagedFiles): Ticket {
                $retainStagedFiles = false;
                $settings = TicketingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $this->authorize($lockedActor, 'tickets.create');
                $this->ensureVerifiedCustomer($lockedActor);

                $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.create', $operationKey, $payload);
                if ($receiptTicket !== null) {
                    return $receiptTicket;
                }

                $customerId = $this->isAdministrator($lockedActor) ? $payload['customer_id'] : (int) $lockedActor->getKey();
                $customer = User::query()->active()->whereNotNull('email_verified_at')->whereKey($customerId)->lockForUpdate()->firstOrFail();
                if (! $customer->hasRole('customer')) {
                    throw ValidationException::withMessages(['customer_id' => 'Choose an active, verified customer account.']);
                }

                $category = Category::query()->whereKey($payload['category_id'])->lockForUpdate()->firstOrFail();
                if (! $category->is_active) {
                    throw ValidationException::withMessages(['category_id' => 'Choose an enabled category.']);
                }
                $priority = Priority::query()->whereKey($payload['priority_id'])->lockForUpdate()->firstOrFail();
                if (! $priority->is_active) {
                    throw ValidationException::withMessages(['priority_id' => 'Choose an enabled priority.']);
                }

                $policy = SlaPolicy::query()->whereKey($settings->current_sla_policy_id)->lockForUpdate()->firstOrFail();
                if ($policy->publication_state !== PublicationState::Published || ! $policy->is_active) {
                    throw ValidationException::withMessages(['sla' => 'The current SLA policy is not available for ticket creation.']);
                }
                $calendar = CalendarVersion::query()->whereKey($policy->calendar_version_id)->lockForUpdate()->firstOrFail();
                if ($calendar->publication_state !== PublicationState::Published || ! $calendar->is_active) {
                    throw ValidationException::withMessages(['sla' => 'The current SLA calendar is not available for ticket creation.']);
                }
                $target = SlaPolicyTarget::query()
                    ->where('sla_policy_id', $policy->getKey())
                    ->where('priority_id', $priority->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($target === null) {
                    throw ValidationException::withMessages(['priority_id' => 'This priority has no target in the current SLA policy.']);
                }

                $startedAt = CarbonImmutable::now('UTC')->startOfSecond();
                $projection = $this->sla->creationProjection($calendar, $startedAt, (int) $target->target_seconds, (int) $policy->warning_percent);
                $number = $this->nextTicketNumber($startedAt);

                $ticket = new Ticket;
                $ticket->forceFill([
                    'number' => $number,
                    'customer_id' => $customer->getKey(),
                    'created_by' => $lockedActor->getKey(),
                    'assigned_agent_id' => null,
                    'category_id' => $category->getKey(),
                    'priority_id' => $priority->getKey(),
                    'subject' => $payload['subject'],
                    'description' => $payload['description'],
                    'status' => TicketStatus::Open,
                    'sla_policy_id' => $policy->getKey(),
                    'calendar_version_id' => $calendar->getKey(),
                    'sla_target_seconds' => $target->target_seconds,
                    'sla_started_at' => $startedAt,
                    'sla_due_at' => $projection['due_at'],
                    'sla_warning_at' => $projection['warning_at'],
                    'sla_paused_at' => null,
                    'sla_consumed_seconds' => 0,
                    'first_breached_at' => null,
                    'resolution_cycle' => 1,
                    'lock_version' => 1,
                    'is_active' => true,
                ])->save();

                if ($externalReference !== null) {
                    TicketExternalReference::query()->forceCreate([
                        'source' => $externalReference['source'],
                        'external_id' => $externalReference['external_id'],
                        'ticket_id' => $ticket->getKey(),
                        'import_run_id' => $externalReference['import_run_id'],
                    ]);
                }

                $this->attachments->attachToTicket($ticket, null, $lockedActor, $stagedFiles);

                $activity = $this->activity->record($ticket, $lockedActor, ActorKind::User, 'ticket.created', $operationKey, [
                    'number' => $ticket->number,
                    'customer_id' => $customer->getKey(),
                    'category_id' => $category->getKey(),
                    'priority_id' => $priority->getKey(),
                    'sla_policy_id' => $policy->getKey(),
                    'calendar_version_id' => $calendar->getKey(),
                    'sla_target_seconds' => $target->target_seconds,
                ]);
                $ticket = $this->assignments->assignAutomatically($ticket, $operationKey);
                $recipientIds = [$customer->getKey(), ...$this->activity->activeAdministratorIds()];
                $this->activity->queueDeliveryIntents($activity, $ticket, 'ticket.created', $recipientIds, "Ticket {$ticket->number} was created.");
                $this->operations->complete($operationKey, $ticket, 'created');
                $retainStagedFiles = true;

                return $ticket->load(['customer', 'category', 'priority', 'slaPolicy', 'calendarVersion']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->attachments->discard($stagedFiles);
            throw $exception;
        }

        if (! $retainStagedFiles) {
            $this->attachments->discard($stagedFiles);
        }

        return $ticket;
    }

    public function findForActor(User $actor, int $ticketId): Ticket
    {
        $this->authorize($actor, 'tickets.view');
        $this->ensureVerifiedCustomer($actor);
        $query = Ticket::query();
        if ($this->isAdministrator($actor)) {
            $query->withTrashed();
        } elseif ($this->isCustomer($actor)) {
            $query->where('customer_id', $actor->getKey());
        } elseif ($this->isAgent($actor)) {
            $query->where('assigned_agent_id', $actor->getKey());
        } else {
            throw new AuthorizationException;
        }

        $ticket = $query->whereKey($ticketId)->with([
            'customer', 'creator', 'assignedAgent', 'category', 'priority', 'slaPolicy', 'calendarVersion',
            'attachments' => function (HasMany $query) use ($actor): void {
                $query->whereNull('ticket_message_id');
                if (! $this->isAdministrator($actor)) {
                    $query->where('is_active', true);
                } else {
                    $query->withTrashed();
                }
            },
        ])->first();

        if ($ticket === null) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticketId]);
        }

        return $ticket;
    }

    public function canEditContent(User $actor, Ticket $ticket): bool
    {
        if ($this->isAdministrator($actor)) {
            return ! $ticket->trashed()
                && $ticket->status !== TicketStatus::Closed
                && $this->permissions->allows($actor, 'tickets.update_content');
        }

        return $this->isCustomer($actor)
            && $actor->email_verified_at !== null
            && $ticket->is_active
            && (int) $ticket->customer_id === (int) $actor->getKey()
            && $ticket->status === TicketStatus::Open
            && $this->permissions->allows($actor, 'tickets.update_content')
            && ! $ticket->messages()->where('visibility', MessageVisibility::Public)
                ->where('author_id', '!=', $ticket->customer_id)->exists();
    }

    public function setAvailability(User $actor, int $ticketId, bool $isActive, int $expectedVersion, string $operationKey): Ticket
    {
        $this->authorize($actor, 'tickets.manage_availability');
        $payload = ['ticket_id' => $ticketId, 'is_active' => $isActive, 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $isActive, $expectedVersion, $operationKey, $payload): Ticket {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, 'tickets.manage_availability');
            if (! $this->isAdministrator($lockedActor)) {
                throw new AuthorizationException;
            }
            $existing = $this->operations->reserve($lockedActor, 'ticket.availability', $operationKey, $payload);
            if ($existing !== null) {
                return $existing;
            }
            $this->assertVersion($ticket, $expectedVersion);
            if ($ticket->is_active !== $isActive) {
                $before = $ticket->is_active;
                $ticket->forceFill([
                    'is_active' => $isActive,
                    'updated_by' => $lockedActor->getKey(),
                    'lock_version' => $ticket->lock_version + 1,
                ])->save();
                $this->activity->record($ticket, $lockedActor, ActorKind::User, $isActive ? 'ticket.enabled' : 'ticket.disabled', $operationKey, [
                    'is_active' => ['before' => $before, 'after' => $isActive],
                ]);
            }
            $this->operations->complete($operationKey, $ticket, 'availability_changed');

            return $ticket;
        }, attempts: 3);
    }

    public function archive(User $actor, int $ticketId, int $expectedVersion, string $operationKey): Ticket
    {
        $this->authorize($actor, 'tickets.archive');
        $payload = ['ticket_id' => $ticketId, 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $expectedVersion, $operationKey, $payload): Ticket {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, 'tickets.archive');
            if (! $this->isAdministrator($lockedActor)) {
                throw new AuthorizationException;
            }
            $existing = $this->operations->reserve($lockedActor, 'ticket.archive', $operationKey, $payload);
            if ($existing !== null) {
                return $existing;
            }
            $this->assertVersion($ticket, $expectedVersion);
            if ($ticket->status !== TicketStatus::Closed) {
                throw ValidationException::withMessages(['ticket' => 'Only closed tickets can be archived.']);
            }
            $ticket->forceFill([
                'is_active' => false,
                'updated_by' => $lockedActor->getKey(),
                'deleted_by' => $lockedActor->getKey(),
                'lock_version' => $ticket->lock_version + 1,
            ])->save();
            $ticket->delete();
            $this->activity->record($ticket, $lockedActor, ActorKind::User, 'ticket.archived', $operationKey, ['is_active' => false]);
            $this->operations->complete($operationKey, $ticket, 'archived');

            return $ticket;
        }, attempts: 3);
    }

    public function restore(User $actor, int $ticketId, int $expectedVersion, string $operationKey): Ticket
    {
        $this->authorize($actor, 'tickets.restore');
        $payload = ['ticket_id' => $ticketId, 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $expectedVersion, $operationKey, $payload): Ticket {
            $ticket = Ticket::withTrashed()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, 'tickets.restore');
            if (! $this->isAdministrator($lockedActor)) {
                throw new AuthorizationException;
            }
            $existing = $this->operations->reserve($lockedActor, 'ticket.restore', $operationKey, $payload);
            if ($existing !== null) {
                return $existing;
            }
            $this->assertVersion($ticket, $expectedVersion);
            if (! $ticket->trashed() || $ticket->status !== TicketStatus::Closed) {
                throw ValidationException::withMessages(['ticket' => 'Only archived closed tickets can be restored.']);
            }
            $ticket->restore();
            $ticket->forceFill([
                'is_active' => false,
                'updated_by' => $lockedActor->getKey(),
                'deleted_by' => null,
                'lock_version' => $ticket->lock_version + 1,
            ])->save();
            $this->activity->record($ticket, $lockedActor, ActorKind::User, 'ticket.restored', $operationKey, ['is_active' => false]);
            $this->operations->complete($operationKey, $ticket, 'restored');

            return $ticket;
        }, attempts: 3);
    }

    public function updateContent(
        User $actor,
        int $ticketId,
        string $subject,
        string $description,
        int $expectedVersion,
        string $operationKey,
    ): Ticket {
        $this->authorize($actor, 'tickets.update_content');
        $payload = ['ticket_id' => $ticketId, 'subject' => trim($subject), 'description' => trim($description), 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $payload, $operationKey, $expectedVersion): Ticket {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, 'tickets.update_content');
            $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.update_content', $operationKey, $payload);
            if ($receiptTicket !== null) {
                return $receiptTicket;
            }

            $this->authorizeTicketMutation($lockedActor, $ticket, 'tickets.update_content');
            if (! $this->canEditContent($lockedActor, $ticket)) {
                throw new AuthorizationException;
            }
            $this->assertVersion($ticket, $expectedVersion);
            $before = ['subject' => $ticket->subject, 'description' => $ticket->description];
            $ticket->forceFill([
                'subject' => $payload['subject'],
                'description' => $payload['description'],
                'updated_by' => $lockedActor->getKey(),
                'lock_version' => $ticket->lock_version + 1,
            ])->save();

            if ($ticket->wasChanged(['subject', 'description'])) {
                $this->activity->record($ticket, $lockedActor, ActorKind::User, 'ticket.content_updated', $operationKey, [
                    'before' => ['subject' => $before['subject']],
                    'after' => ['subject' => $ticket->subject],
                ]);
            }

            $this->operations->complete($operationKey, $ticket, 'updated');

            return $ticket;
        }, attempts: 3);
    }

    /** @return list<TicketTransition> */
    public function availableTransitions(User $actor, Ticket $ticket): array
    {
        $isAdmin = $this->isAdministrator($actor);
        $isOwner = $this->isCustomer($actor) && (int) $ticket->customer_id === (int) $actor->getKey();
        $isAssignedAgent = $this->isAgent($actor) && (int) $ticket->assigned_agent_id === (int) $actor->getKey();
        $available = [];

        if (! $ticket->is_active && ! $isAdmin) {
            return [];
        }

        if ($isAdmin || $isAssignedAgent) {
            if ($ticket->status === TicketStatus::Open) {
                $available[] = TicketTransition::Start;
            }
            if (in_array($ticket->status, [TicketStatus::Open, TicketStatus::InProgress], true)) {
                $available[] = TicketTransition::Wait;
                $available[] = TicketTransition::Resolve;
            }
            if ($ticket->status === TicketStatus::WaitingForCustomer) {
                $available[] = TicketTransition::Resume;
                $available[] = TicketTransition::Resolve;
            }
        }

        if (($isAdmin || $isOwner) && $ticket->status === TicketStatus::Resolved) {
            $available[] = TicketTransition::Close;
            $available[] = TicketTransition::Reopen;
        }

        return array_values(array_filter($available, fn (TicketTransition $transition): bool => $this->permissions->allows($actor, $this->transitionPermission($transition))));
    }

    /** @param list<StagedTicketAttachment> $stagedFiles */
    public function transition(
        User $actor,
        int $ticketId,
        TicketTransition $transition,
        int $expectedVersion,
        string $operationKey,
        ?string $publicMessage = null,
        ?string $reason = null,
        array $stagedFiles = [],
    ): Ticket {
        $permission = $this->transitionPermission($transition);
        try {
            $this->authorize($actor, $permission);
        } catch (Throwable $exception) {
            $this->attachments->discard($stagedFiles);
            throw $exception;
        }
        $payload = [
            'ticket_id' => $ticketId,
            'transition' => $transition->value,
            'lock_version' => $expectedVersion,
            'public_message' => $publicMessage === null ? null : trim($publicMessage),
            'reason' => $reason === null ? null : trim($reason),
            'attachments' => $this->attachments->fingerprint($stagedFiles),
        ];
        $needsMessage = in_array($transition, [TicketTransition::Wait, TicketTransition::Resolve, TicketTransition::Reopen], true);
        if ($stagedFiles !== [] && ! $needsMessage) {
            $this->attachments->discard($stagedFiles);
            throw ValidationException::withMessages(['attachments' => 'Attachments can only be added with a public ticket message.']);
        }
        $retainStagedFiles = false;

        try {
            $ticket = DB::transaction(function () use ($actor, $ticketId, $transition, $expectedVersion, $operationKey, $payload, $stagedFiles, $needsMessage, &$retainStagedFiles): Ticket {
                $retainStagedFiles = false;
                $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
                $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $this->authorize($lockedActor, $this->transitionPermission($transition));
                $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.transition', $operationKey, $payload);
                if ($receiptTicket !== null) {
                    return $receiptTicket;
                }

                $this->assertVersion($ticket, $expectedVersion);
                $this->authorizeTransition($lockedActor, $ticket, $transition);
                $now = CarbonImmutable::now('UTC')->startOfSecond();
                $oldStatus = $ticket->status;
                $oldConsumed = $this->sla->consumedSeconds($ticket, $now);
                if (in_array($transition, [TicketTransition::Wait, TicketTransition::Resolve], true)) {
                    $this->escalations->recordCurrentStageInTransaction($ticket, $now);
                }
                $firstBreachAt = $ticket->first_breached_at ?? $this->sla->breachAt($ticket, $now);
                $changes = ['from' => $oldStatus->value, 'to' => $this->targetStatus($transition)->value];
                if ($transition === TicketTransition::Resolve) {
                    $changes += [
                        'resolution_cycle' => (int) $ticket->resolution_cycle,
                        'sla_target_seconds' => (int) $ticket->sla_target_seconds,
                        'sla_consumed_seconds' => $oldConsumed,
                        'sla_met' => $oldConsumed <= (int) $ticket->sla_target_seconds,
                        'assigned_agent_id' => $ticket->assigned_agent_id,
                    ];
                }
                $safeMessage = $payload['public_message'];
                $messageId = null;

                if ($needsMessage) {
                    if ($safeMessage === null || trim($safeMessage) === '') {
                        throw ValidationException::withMessages(['public_message' => 'A public message is required for this transition.']);
                    }
                    $message = $this->conversations->createTransitionMessageInTransaction(
                        $ticket,
                        $lockedActor,
                        $safeMessage,
                        $operationKey,
                        $stagedFiles,
                    );
                    $messageId = (int) $message->getKey();
                    $changes['public_message_id'] = $messageId;
                }
                if ($transition === TicketTransition::Reopen) {
                    $changes['reason'] = $payload['reason'];
                }

                if ($transition === TicketTransition::Wait || $transition === TicketTransition::Resolve) {
                    $this->closeOpenInterval($ticket, $now, $lockedActor);
                }
                if ($transition === TicketTransition::Resume || $transition === TicketTransition::Reopen) {
                    $this->closeOpenInterval($ticket, $now, $lockedActor);
                }
                if ($transition === TicketTransition::Close) {
                    $this->closeOpenInterval($ticket, $now, $lockedActor);
                }

                $attributes = [
                    'status' => $this->targetStatus($transition),
                    'updated_by' => $lockedActor->getKey(),
                    'lock_version' => $ticket->lock_version + 1,
                    'sla_consumed_seconds' => $oldConsumed,
                    'first_breached_at' => $ticket->first_breached_at ?? $firstBreachAt,
                ];

                if ($transition === TicketTransition::Wait) {
                    $attributes += ['sla_paused_at' => $now, 'sla_due_at' => null, 'sla_warning_at' => null];
                    $ticket->slaIntervals()->forceCreate([
                        'kind' => 'waiting', 'started_at' => $now, 'ended_at' => null,
                        'started_by' => $lockedActor->getKey(), 'ended_by' => null,
                        'resolution_cycle' => $ticket->resolution_cycle,
                    ]);
                } elseif ($transition === TicketTransition::Resume) {
                    $attributes += ['sla_paused_at' => null];
                } elseif ($transition === TicketTransition::Resolve) {
                    $attributes += ['resolved_at' => $now, 'sla_paused_at' => null, 'sla_due_at' => null, 'sla_warning_at' => null];
                    $ticket->slaIntervals()->forceCreate([
                        'kind' => 'resolved', 'started_at' => $now, 'ended_at' => null,
                        'started_by' => $lockedActor->getKey(), 'ended_by' => null,
                        'resolution_cycle' => $ticket->resolution_cycle,
                    ]);
                } elseif ($transition === TicketTransition::Close) {
                    $attributes['closed_at'] = $now;
                } elseif ($transition === TicketTransition::Reopen) {
                    $attributes += [
                        'resolved_at' => null,
                        'closed_at' => null,
                        'resolution_cycle' => $ticket->resolution_cycle + 1,
                        'sla_paused_at' => null,
                    ];
                }

                if (in_array($transition, [TicketTransition::Resume, TicketTransition::Reopen], true)) {
                    $projection = $this->sla->activeProjection($ticket, $now);
                    $attributes += [
                        'sla_warning_at' => $projection['warning_at'],
                        'sla_due_at' => $projection['due_at'],
                    ];
                }

                $ticket->forceFill($attributes)->save();
                if ($transition === TicketTransition::Reopen) {
                    $this->escalations->recordCurrentStageInTransaction($ticket, $now);
                }

                $activity = $this->activity->record($ticket, $lockedActor, ActorKind::User, 'ticket.'.$transition->value, $operationKey, $changes);
                $recipients = [$ticket->customer_id];
                if ($ticket->assigned_agent_id !== null) {
                    $recipients[] = $ticket->assigned_agent_id;
                } elseif ($transition !== TicketTransition::Start) {
                    $recipients = [...$recipients, ...$this->activity->activeAdministratorIds()];
                }
                $this->activity->queueDeliveryIntents($activity, $ticket, 'ticket.'.$transition->value, $recipients, "Ticket {$ticket->number} status changed to {$ticket->status->value}.");
                $this->operations->complete($operationKey, $ticket, $transition->value);
                $retainStagedFiles = true;

                return $ticket->load(['customer', 'category', 'priority', 'slaPolicy', 'calendarVersion']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->attachments->discard($stagedFiles);
            throw $exception;
        }

        if (! $retainStagedFiles) {
            $this->attachments->discard($stagedFiles);
        }

        return $ticket;
    }

    public function changePriority(
        User $actor,
        int $ticketId,
        int $priorityId,
        int $expectedVersion,
        string $operationKey,
    ): Ticket {
        $this->authorize($actor, 'tickets.change_priority');
        $payload = ['ticket_id' => $ticketId, 'priority_id' => $priorityId, 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $priorityId, $expectedVersion, $operationKey, $payload): Ticket {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, 'tickets.change_priority');
            $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.change_priority', $operationKey, $payload);
            if ($receiptTicket !== null) {
                return $receiptTicket;
            }

            $this->authorizeTicketMutation($lockedActor, $ticket, 'tickets.change_priority');
            $this->assertVersion($ticket, $expectedVersion);
            if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
                throw ValidationException::withMessages(['priority_id' => 'Priority cannot be changed after a ticket is resolved.']);
            }

            $priority = Priority::query()->whereKey($priorityId)->lockForUpdate()->firstOrFail();
            if (! $priority->is_active) {
                throw ValidationException::withMessages(['priority_id' => 'Choose an enabled priority.']);
            }
            $target = SlaPolicyTarget::query()
                ->where('sla_policy_id', $ticket->sla_policy_id)
                ->where('priority_id', $priority->getKey())
                ->lockForUpdate()
                ->first();
            if ($target === null) {
                throw ValidationException::withMessages(['priority_id' => 'This priority has no target in the ticket’s pinned SLA policy.']);
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $oldPriorityId = (int) $ticket->priority_id;
            $oldTarget = (int) $ticket->sla_target_seconds;
            $this->escalations->recordCurrentStageInTransaction($ticket, $now);
            $consumed = $this->sla->consumedSeconds($ticket, $now);
            $oldBreachAt = $ticket->first_breached_at ?? $this->sla->breachAt($ticket, $now);
            $ticket->forceFill([
                'priority_id' => $priority->getKey(),
                'sla_target_seconds' => $target->target_seconds,
                'sla_consumed_seconds' => $consumed,
                'first_breached_at' => $ticket->first_breached_at ?? $oldBreachAt,
                'updated_by' => $lockedActor->getKey(),
                'lock_version' => $ticket->lock_version + 1,
            ])->save();

            if (in_array($ticket->status, [TicketStatus::Open, TicketStatus::InProgress], true)) {
                $projection = $this->sla->activeProjection($ticket, $now);
                $newBreachAt = $ticket->first_breached_at ?? $this->sla->breachAt($ticket, $now);
                $ticket->forceFill([
                    'sla_warning_at' => $projection['warning_at'],
                    'sla_due_at' => $projection['due_at'],
                    'first_breached_at' => $ticket->first_breached_at ?? $newBreachAt,
                ])->save();
                $this->escalations->recordCurrentStageInTransaction($ticket, $now);
            }

            $activity = $this->activity->record($ticket, $lockedActor, ActorKind::User, 'ticket.priority_changed', $operationKey, [
                'priority_id' => ['before' => $oldPriorityId, 'after' => $priority->getKey()],
                'sla_target_seconds' => ['before' => $oldTarget, 'after' => $target->target_seconds],
                'sla_consumed_seconds' => $consumed,
                'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            ]);
            $this->activity->queueDeliveryIntents($activity, $ticket, 'ticket.priority_changed', $this->activity->participantIds($ticket), "Ticket {$ticket->number} priority changed to {$priority->name}.");
            $this->operations->complete($operationKey, $ticket, 'priority_changed');

            return $ticket->load('priority');
        }, attempts: 3);
    }

    private function nextTicketNumber(CarbonImmutable $createdAt): string
    {
        $year = (int) $createdAt->setTimezone('Asia/Dhaka')->format('Y');
        DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        $sequence = DB::table('ticket_sequences')->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            throw new HttpException(503, 'Ticket numbering is temporarily unavailable.');
        }

        $next = (int) $sequence->last_number + 1;
        DB::table('ticket_sequences')->where('year', $year)->update(['last_number' => $next]);

        return sprintf('TKT-%04d-%s', $year, str_pad((string) $next, 6, '0', STR_PAD_LEFT));
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

    private function authorizeTransition(User $actor, Ticket $ticket, TicketTransition $transition): void
    {
        $this->authorizeTicketMutation($actor, $ticket, $this->transitionPermission($transition));
        $available = $this->availableTransitions($actor, $ticket);
        if (! in_array($transition, $available, true)) {
            throw ValidationException::withMessages(['transition' => 'This ticket cannot make that status transition.']);
        }
    }

    private function authorizeTicketMutation(User $actor, Ticket $ticket, string $permission): void
    {
        $this->authorize($actor, $permission);
        if (! $ticket->is_active && ! $this->isAdministrator($actor)) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
        }
        if ($this->isAdministrator($actor)) {
            return;
        }
        if ($this->isCustomer($actor) && (int) $ticket->customer_id === (int) $actor->getKey()) {
            return;
        }
        if ($this->isAgent($actor) && (int) $ticket->assigned_agent_id === (int) $actor->getKey()) {
            return;
        }

        throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->getKey()]);
    }

    private function assertVersion(Ticket $ticket, int $expectedVersion): void
    {
        if ((int) $ticket->lock_version !== $expectedVersion) {
            throw new HttpException(409, 'This ticket changed after the page loaded. Refresh and try again.');
        }
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function ensureVerifiedCustomer(User $actor): void
    {
        if ($this->isCustomer($actor) && $actor->email_verified_at === null) {
            throw new AuthorizationException;
        }
    }

    private function isAdministrator(User $actor): bool
    {
        return in_array('admin', $actor->tyroRoleSlugs(), true);
    }

    private function isAgent(User $actor): bool
    {
        return in_array('agent', $actor->tyroRoleSlugs(), true);
    }

    private function isCustomer(User $actor): bool
    {
        return in_array('customer', $actor->tyroRoleSlugs(), true);
    }

    private function transitionPermission(TicketTransition $transition): string
    {
        return match ($transition) {
            TicketTransition::Start => 'tickets.start',
            TicketTransition::Wait => 'tickets.wait',
            TicketTransition::Resume => 'tickets.resume',
            TicketTransition::Resolve => 'tickets.resolve',
            TicketTransition::Close => 'tickets.close',
            TicketTransition::Reopen => 'tickets.reopen',
        };
    }

    private function targetStatus(TicketTransition $transition): TicketStatus
    {
        return match ($transition) {
            TicketTransition::Start, TicketTransition::Resume, TicketTransition::Reopen => TicketStatus::InProgress,
            TicketTransition::Wait => TicketStatus::WaitingForCustomer,
            TicketTransition::Resolve => TicketStatus::Resolved,
            TicketTransition::Close => TicketStatus::Closed,
        };
    }
}
