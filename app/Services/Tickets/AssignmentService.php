<?php

namespace App\Services\Tickets;

use App\Enums\ActorKind;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\TicketingSetting;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssignmentService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly TicketActivityService $activity,
        private readonly TicketOperationService $operations,
    ) {}

    /**
     * Assign the least-loaded agent with a round-robin-style fairness tie-break.
     *
     * Selection steps:
     * 1. Lock the shared assignment coordinator so ticket creation, claims, manual assignment,
     *    eligibility changes, and agent deactivation follow the same lock order.
     * 2. Lock the ticket and stop if it is already assigned or is inactive, archived, resolved,
     *    or closed.
     * 3. Load active agent profiles enabled for automatic assignment whose users and agent roles
     *    are also active; lock the profiles and then the corresponding user rows.
     * 4. Count each candidate's non-archived Open, In Progress, and Waiting for Customer tickets.
     * 5. Choose the lowest workload; ties go to the agent least recently auto-assigned, then the
     *    lowest user ID for a stable final tie-break.
     * 6. Recheck the selected user's agent role, assign and increment the ticket lock version,
     *    update the agent's last-auto-assigned time, and write activity/notification records.
     *
     * Every step runs inside the ticket creation transaction, so a failure rolls back the ticket
     * and assignment together.
     */
    public function assignAutomatically(Ticket $ticket, string $operationKey): Ticket
    {
        return DB::transaction(function () use ($ticket, $operationKey): Ticket {
            // Step 1: serialize assignment-related work before taking ticket, profile, or user locks.
            $this->lockCoordinator();

            // Step 2: re-read the ticket under lock; another operation may have assigned or closed it.
            $lockedTicket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedTicket->assigned_agent_id !== null || ! $this->isAssignable($lockedTicket)) {
                return $lockedTicket;
            }

            // Step 3: only agents with an active profile and automatic assignment enabled are candidates.
            $profiles = $this->profilesForAgents(automaticOnly: true, lock: true);
            if ($profiles->isEmpty()) {
                $this->activity->record(
                    $lockedTicket,
                    null,
                    ActorKind::System,
                    'ticket.auto_assignment_skipped',
                    $operationKey,
                    ['reason' => 'no_eligible_agent'],
                );

                return $lockedTicket;
            }

            $agentIds = array_values($profiles->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all());
            // Lock active user rows in ID order, then discard any profile whose user is no longer active.
            $users = $this->lockActiveUsers($agentIds);
            $profiles = $profiles->filter(fn (AgentProfile $profile): bool => $users->has((int) $profile->user_id));
            if ($profiles->isEmpty()) {
                $this->activity->record(
                    $lockedTicket,
                    null,
                    ActorKind::System,
                    'ticket.auto_assignment_skipped',
                    $operationKey,
                    ['reason' => 'no_eligible_agent'],
                );

                return $lockedTicket;
            }

            // Step 4: workload includes non-archived tickets in states that still need an agent's work.
            $workloads = $this->workloads(array_values($profiles->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all()));

            // Step 5: least-loaded first; use last assignment time and user ID to break ties predictably.
            $profiles = $profiles->sort(function (AgentProfile $left, AgentProfile $right) use ($workloads): int {
                $leftWorkload = (int) ($workloads[(int) $left->user_id] ?? 0);
                $rightWorkload = (int) ($workloads[(int) $right->user_id] ?? 0);
                if ($leftWorkload !== $rightWorkload) {
                    return $leftWorkload <=> $rightWorkload;
                }

                $leftAssignedAt = $left->last_auto_assigned_at?->getTimestamp();
                $rightAssignedAt = $right->last_auto_assigned_at?->getTimestamp();
                if ($leftAssignedAt === null && $rightAssignedAt !== null) {
                    return -1;
                }
                if ($leftAssignedAt !== null && $rightAssignedAt === null) {
                    return 1;
                }
                if ($leftAssignedAt !== $rightAssignedAt) {
                    return ($leftAssignedAt ?? 0) <=> ($rightAssignedAt ?? 0);
                }

                return (int) $left->user_id <=> (int) $right->user_id;
            })->first();

            if ($profiles === null) {
                return $lockedTicket;
            }

            $agent = $users->get((int) $profiles->user_id);
            // Step 6: confirm the chosen locked user still carries the active agent role before writing.
            if ($agent === null || ! in_array('agent', $agent->tyroRoleSlugs(), true)) {
                $this->activity->record(
                    $lockedTicket,
                    null,
                    ActorKind::System,
                    'ticket.auto_assignment_skipped',
                    $operationKey,
                    ['reason' => 'no_eligible_agent'],
                );

                return $lockedTicket;
            }

            // Step 7: assign, advance the fairness timestamp, and record the outcome in the same transaction.
            $this->writeAssignment($lockedTicket, $agent, null);
            $profiles->forceFill(['last_auto_assigned_at' => CarbonImmutable::now('UTC')])->save();
            $activity = $this->activity->record($lockedTicket,null,ActorKind::System,'ticket.auto_assigned',$operationKey,['assigned_agent_id' => ['before' => null, 'after' => $agent->getKey()], 'workload_before' => (int) ($workloads[(int) $agent->getKey()] ?? 0)]);
            $this->activity->queueDeliveryIntents(
                $activity,
                $lockedTicket,
                'ticket.auto_assigned',
                [$lockedTicket->customer_id, $agent->getKey(), ...$this->activity->activeAdministratorIds()],
                "Ticket {$lockedTicket->number} was assigned to {$agent->name}.",
            );

            return $lockedTicket;
        }, attempts: 3);
    }

    public function assign(User $actor, int $ticketId, int $agentId, int $expectedVersion, string $operationKey): Ticket
    {
        // Manual assignment has the same coordinator/row-lock discipline, but does not require
        // automatic assignment to be enabled for the selected agent.
        $this->authorizeAdministrator($actor);
        $payload = ['ticket_id' => $ticketId, 'agent_id' => $agentId, 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $agentId, $expectedVersion, $operationKey, $payload): Ticket {
            $this->lockCoordinator();
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $profile = AgentProfile::query()->active()->where('user_id', $agentId)->lockForUpdate()->first();
            $lockedUsers = $this->lockActiveUsers([(int) $actor->getKey(), $agentId]);
            $lockedActor = $lockedUsers->get((int) $actor->getKey());
            $agent = $lockedUsers->get($agentId);
            if ($lockedActor === null) {
                throw new AuthorizationException;
            }
            $this->authorizeAdministrator($lockedActor);
            $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.assign', $operationKey, $payload);
            if ($receiptTicket !== null) {
                return $receiptTicket;
            }

            // Reject stale ticket forms and terminal/inactive tickets before checking the target agent.
            $this->assertVersion($ticket, $expectedVersion);
            $this->assertAssignable($ticket);

            // Manual targets still need an active user, active profile, and the active agent role.
            if ($agent === null || $profile === null || ! in_array('agent', $agent->tyroRoleSlugs(), true)) {
                throw ValidationException::withMessages(['agent_id' => 'Choose an active agent with an active agent profile.']);
            }

            if ((int) $ticket->assigned_agent_id === $agentId) {
                $this->operations->complete($operationKey, $ticket, 'unchanged');

                return $ticket;
            }

            $previousAgentId = $ticket->assigned_agent_id === null ? null : (int) $ticket->assigned_agent_id;
            $this->writeAssignment($ticket, $agent, $lockedActor);
            $activity = $this->activity->record(
                $ticket,
                $lockedActor,
                ActorKind::User,
                'ticket.assigned',
                $operationKey,
                ['assigned_agent_id' => ['before' => $previousAgentId, 'after' => $agent->getKey()], 'mode' => 'manual'],
            );
            $recipients = [$ticket->customer_id, $agent->getKey(), ...$this->activity->activeAdministratorIds()];
            if ($previousAgentId !== null) {
                $recipients[] = $previousAgentId;
            }
            $this->activity->queueDeliveryIntents($activity, $ticket, 'ticket.assigned', $recipients, "Ticket {$ticket->number} was assigned to {$agent->name}.");
            $this->operations->complete($operationKey, $ticket, 'assigned');

            return $ticket;
        }, attempts: 3);
    }

    public function claim(User $actor, int $ticketId, int $expectedVersion, string $operationKey): Ticket
    {
        // A claim is available to an active agent with an active profile; auto-assignment eligibility
        // is a separate setting and does not control an agent's ability to claim a queue ticket.
        $this->authorizeAgent($actor);
        $payload = ['ticket_id' => $ticketId, 'lock_version' => $expectedVersion];

        return DB::transaction(function () use ($actor, $ticketId, $expectedVersion, $operationKey, $payload): Ticket {
            $this->lockCoordinator();
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $profile = AgentProfile::query()->active()->where('user_id', $actor->getKey())->lockForUpdate()->first();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeAgent($lockedActor);
            $receiptTicket = $this->operations->reserve($lockedActor, 'ticket.claim', $operationKey, $payload);
            if ($receiptTicket !== null) {
                return $receiptTicket;
            }
            $this->assertVersion($ticket, $expectedVersion);
            $this->assertAssignable($ticket);
            if ($profile === null) {
                throw new AuthorizationException;
            }

            // The queue ticket must still be unassigned after its row lock; a competing claim gets 409.
            if ($ticket->assigned_agent_id !== null) {
                throw new HttpException(409, 'This ticket has already been claimed. Refresh the ticket list.');
            }

            $this->writeAssignment($ticket, $lockedActor, $lockedActor);
            $activity = $this->activity->record(
                $ticket,
                $lockedActor,
                ActorKind::User,
                'ticket.claimed',
                $operationKey,
                ['assigned_agent_id' => ['before' => null, 'after' => $lockedActor->getKey()], 'mode' => 'claim'],
            );
            $this->activity->queueDeliveryIntents(
                $activity,
                $ticket,
                'ticket.claimed',
                [$ticket->customer_id, $lockedActor->getKey(), ...$this->activity->activeAdministratorIds()],
                "Ticket {$ticket->number} was claimed by {$lockedActor->name}.",
            );
            $this->operations->complete($operationKey, $ticket, 'claimed');

            return $ticket;
        }, attempts: 3);
    }

    /** @return Collection<int, AgentProfile> */
    public function agentRoster(User $actor): Collection
    {
        $this->authorizeAgentManagement($actor);

        return $this->profilesForAgents(automaticOnly: false, lock: false)
            ->sortBy(fn (AgentProfile $profile): string => mb_strtolower((string) $profile->user?->name))
            ->values();
    }

    /** @return Collection<int, AgentProfile> */
    public function assignableAgents(User $actor): Collection
    {
        $this->authorizeAdministrator($actor);

        return $this->profilesForAgents(automaticOnly: false, lock: false)->load('user')->values();
    }

    public function setAutoAssignment(User $actor, int $agentId, bool $enabled, string $operationKey): void
    {
        $this->authorizeAgentManagement($actor);
        DB::transaction(function () use ($actor, $agentId, $enabled, $operationKey): void {
            $this->lockCoordinator();
            $profile = AgentProfile::query()->active()->where('user_id', $agentId)->lockForUpdate()->firstOrFail();
            $lockedUsers = $this->lockActiveUsers([(int) $actor->getKey(), $agentId]);
            $lockedActor = $lockedUsers->get((int) $actor->getKey());
            $agent = $lockedUsers->get($agentId);
            if ($lockedActor === null || $agent === null) {
                throw new AuthorizationException;
            }
            $this->authorizeAgentManagement($lockedActor);
            if (! in_array('agent', $agent->tyroRoleSlugs(), true)) {
                throw ValidationException::withMessages(['agent' => 'Only active agents can be enabled for automatic assignment.']);
            }
            if ($profile->auto_assignment_enabled === $enabled) {
                return;
            }

            $wasEnabled = $profile->auto_assignment_enabled;
            $profile->forceFill([
                'auto_assignment_enabled' => $enabled,
                'updated_by' => $lockedActor->getKey(),
            ])->save();
            $this->activity->recordAgentEligibility($lockedActor, $profile, $wasEnabled, $enabled, $operationKey);
        }, attempts: 3);
    }

    /** Must be called inside a transaction before ticket/profile/user locks. */
    public function lockCoordinator(): TicketingSetting
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The assignment coordinator must be locked inside a database transaction.');
        }

        return TicketingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock all live assigned tickets and the agent profile before the caller locks user rows.
     *
     * @return Collection<int, Ticket>
     */
    public function lockAssignmentsForDeactivation(int $agentId): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Agent assignments must be locked inside a database transaction.');
        }

        $tickets = Ticket::query()
            ->where('assigned_agent_id', $agentId)
            ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        AgentProfile::query()->where('user_id', $agentId)->orderBy('id')->lockForUpdate()->first();

        return $tickets;
    }

    /** @param Collection<int, Ticket> $tickets */
    public function unassignForDeactivation(Collection $tickets, User $agent, User $actor, string $reason): void
    {
        foreach ($tickets as $ticket) {
            if ((int) $ticket->assigned_agent_id !== (int) $agent->getKey()) {
                continue;
            }

            $ticket->forceFill([
                'assigned_agent_id' => null,
                'updated_by' => $actor->getKey(),
                'lock_version' => $ticket->lock_version + 1,
            ])->save();
            $operationKey = (string) Str::uuid();
            $activity = $this->activity->record(
                $ticket,
                $actor,
                ActorKind::User,
                'ticket.unassigned_agent_deactivated',
                $operationKey,
                ['assigned_agent_id' => ['before' => $agent->getKey(), 'after' => null], 'reason' => $reason],
            );
            $this->activity->queueDeliveryIntents(
                $activity,
                $ticket,
                'ticket.unassigned_agent_deactivated',
                [$ticket->customer_id, ...$this->activity->activeAdministratorIds()],
                "Ticket {$ticket->number} is unassigned because its agent account was deactivated.",
            );
        }
    }

    /** @return Collection<int, AgentProfile> */
    private function profilesForAgents(bool $automaticOnly, bool $lock): Collection
    {
        // The role and profile conditions are deliberate: a user with only the agent role is not
        // assignable until their agent_profiles row exists and is active.
        $query = AgentProfile::query()
            ->active()
            ->when($automaticOnly, fn (Builder $builder) => $builder->where('auto_assignment_enabled', true))
            ->with(['user' => function (BelongsTo $relation): void {
                $relation->withCount(['assignedTickets as live_assignment_count' => function (Builder $ticketQuery): void {
                    $ticketQuery->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer]);
                }]);
            }])
            ->whereHas('user', function (Builder $query): void {
                $query->active()->whereHas('roles', function (Builder $roleQuery): void {
                    $roleQuery->where('name', 'agent')->where('guard_name', 'web')->where('is_active', true)->whereNull('deleted_at');
                });
            })
            ->orderBy('user_id');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /** @param list<int> $userIds
     * @return Collection<int, User>
     */
    private function lockActiveUsers(array $userIds): Collection
    {
        return User::query()->active()->whereIn('id', array_values(array_unique($userIds)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param list<int> $agentIds
     * @return array<int, int>
     */
    private function workloads(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        // Keep this definition aligned with the open-work states shown in the assignment roster.
        return DB::table('tickets')
            ->select('assigned_agent_id')
            ->selectRaw('COUNT(*) AS workload')
            ->whereIn('assigned_agent_id', $agentIds)
            ->whereNull('deleted_at')
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value, TicketStatus::WaitingForCustomer->value])
            ->groupBy('assigned_agent_id')
            ->pluck('workload', 'assigned_agent_id')
            ->mapWithKeys(fn (mixed $workload, mixed $agentId): array => [(int) $agentId => (int) $workload])
            ->all();
    }

    private function writeAssignment(Ticket $ticket, User $agent, ?User $actor): void
    {
        $ticket->forceFill([
            'assigned_agent_id' => $agent->getKey(),
            'updated_by' => $actor?->getKey(),
            'lock_version' => $ticket->lock_version + 1,
        ])->save();
    }

    private function assertAssignable(Ticket $ticket): void
    {
        if (! $this->isAssignable($ticket)) {
            throw ValidationException::withMessages(['ticket' => 'Only active, nonterminal tickets can be assigned.']);
        }
    }

    private function isAssignable(Ticket $ticket): bool
    {
        return $ticket->is_active
            && ! $ticket->trashed()
            && in_array($ticket->status, [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer], true);
    }

    private function assertVersion(Ticket $ticket, int $expectedVersion): void
    {
        if ((int) $ticket->lock_version !== $expectedVersion) {
            throw new HttpException(409, 'This ticket changed after the page loaded. Refresh and try again.');
        }
    }

    private function authorizeAdministrator(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'tickets.assign')) {
            throw new AuthorizationException;
        }
    }

    private function authorizeAgent(User $actor): void
    {
        if (! in_array('agent', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'tickets.claim')) {
            throw new AuthorizationException;
        }
    }

    private function authorizeAgentManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'agents.manage')) {
            throw new AuthorizationException;
        }
    }
}
