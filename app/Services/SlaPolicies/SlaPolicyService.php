<?php

namespace App\Services\SlaPolicies;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Enums\PublicationState;
use App\Enums\SlaPolicyBulkAction;
use App\Models\ActivityEntry;
use App\Models\CalendarVersion;
use App\Models\Priority;
use App\Models\SlaPolicy;
use App\Models\TicketingSetting;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\BulkOperations\BulkOperationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SlaPolicyService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly BulkOperationService $bulkOperations,
    ) {}

    /** @param array{search?: string|null, status?: string|null} $filters
     * @return LengthAwarePaginator<int, SlaPolicy>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeManagement($actor);
        $status = in_array($filters['status'] ?? 'all', ['all', 'draft', 'published', 'inactive', 'archived'], true)
            ? ($filters['status'] ?? 'all')
            : 'all';
        $query = SlaPolicy::query()->with(['calendarVersion', 'targets.priority']);
        if (in_array($status, ['all', 'archived'], true)) {
            $query->withTrashed();
        }
        if ($status === 'draft') {
            $query->where('publication_state', PublicationState::Draft);
        } elseif ($status === 'published') {
            $query->where('publication_state', PublicationState::Published)->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'archived') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderByDesc('version_no')->paginate(15);
    }

    /** @return list<SlaPolicy> */
    public function publishedPolicies(User $actor): array
    {
        $this->authorizeManagement($actor);

        return array_values(SlaPolicy::query()
            ->where('publication_state', PublicationState::Published)
            ->where('is_active', true)
            ->orderByDesc('version_no')
            ->get()
            ->all());
    }

    /** @return list<CalendarVersion> */
    public function usableCalendars(User $actor): array
    {
        $this->authorizeManagement($actor);

        return array_values(CalendarVersion::query()
            ->where('publication_state', PublicationState::Published)
            ->where('is_active', true)
            ->orderByDesc('version_no')
            ->get()
            ->all());
    }

    /** @return list<Priority> */
    public function activePriorities(User $actor): array
    {
        $this->authorizeManagement($actor);

        return array_values(Priority::query()->active()->orderBy('sort_order')->orderBy('id')->get()->all());
    }

    public function cloneDraft(User $actor, int $sourcePolicyId): SlaPolicy
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($actor, $sourcePolicyId): SlaPolicy {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $source = SlaPolicy::query()->with('targets')->whereKey($sourcePolicyId)->lockForUpdate()->firstOrFail();
            if ($source->publication_state !== PublicationState::Published || ! $source->is_active) {
                throw ValidationException::withMessages(['source_policy_id' => 'Choose an enabled, published SLA policy.']);
            }

            $version = (int) SlaPolicy::withTrashed()->max('version_no') + 1;
            $draft = new SlaPolicy;
            $draft->forceFill([
                'version_no' => $version,
                'name' => mb_substr("Draft of {$source->name}", 0, 100),
                'calendar_version_id' => $source->calendar_version_id,
                'warning_percent' => $source->warning_percent,
                'publication_state' => PublicationState::Draft,
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ])->save();

            foreach ($source->targets as $target) {
                $draft->targets()->forceCreate([
                    'priority_id' => $target->priority_id,
                    'target_seconds' => $target->target_seconds,
                ]);
            }

            $this->recordActivity($draft, $actor, 'sla_policy.draft_created', [
                'source_version' => $source->version_no,
                'calendar_version_id' => $source->calendar_version_id,
                'target_count' => $draft->targets()->count(),
            ]);

            return $draft;
        }, attempts: 3);
    }

    /** @param list<array{priority_id: int, target_seconds: int}> $targets */
    public function updateDraft(
        SlaPolicy $policy,
        User $actor,
        string $name,
        int $calendarVersionId,
        int $warningPercent,
        array $targets,
    ): SlaPolicy {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($policy, $actor, $name, $calendarVersionId, $warningPercent, $targets): SlaPolicy {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = SlaPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDraft($locked);
            $calendar = CalendarVersion::query()->whereKey($calendarVersionId)->lockForUpdate()->firstOrFail();
            if ($calendar->publication_state !== PublicationState::Published || ! $calendar->is_active) {
                throw ValidationException::withMessages(['calendar_version_id' => 'Choose an enabled, published calendar version.']);
            }
            $this->validateTargetInput($calendar, $targets);
            $this->ensureArchivedTargetsAreRetained($locked, $targets);

            $before = [
                'name' => $locked->name,
                'calendar_version_id' => $locked->calendar_version_id,
                'warning_percent' => $locked->warning_percent,
                'targets' => $locked->targets()->orderBy('priority_id')->get(['priority_id', 'target_seconds'])->toArray(),
            ];
            $locked->forceFill([
                'name' => $name,
                'calendar_version_id' => $calendarVersionId,
                'warning_percent' => $warningPercent,
                'updated_by' => $actor->getKey(),
            ])->save();
            $locked->targets()->delete();
            foreach ($targets as $target) {
                $locked->targets()->forceCreate($target);
            }

            $this->recordActivity($locked, $actor, 'sla_policy.draft_updated', [
                'before' => $before,
                'after' => [
                    'name' => $name,
                    'calendar_version_id' => $calendarVersionId,
                    'warning_percent' => $warningPercent,
                    'targets' => $targets,
                ],
            ]);

            return $locked->load(['calendarVersion', 'targets.priority']);
        }, attempts: 3);
    }

    public function publish(SlaPolicy $policy, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($policy, $actor): void {
            $settings = $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = SlaPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDraft($locked);
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['policy' => 'Enable the SLA policy before publishing it.']);
            }

            $calendar = CalendarVersion::query()->whereKey($locked->calendar_version_id)->lockForUpdate()->firstOrFail();
            if ($calendar->publication_state !== PublicationState::Published || ! $calendar->is_active) {
                throw ValidationException::withMessages(['policy' => 'The selected calendar must be enabled and published.']);
            }
            $targets = $locked->targets()->orderBy('priority_id')->get();
            if ($targets->isEmpty()) {
                throw ValidationException::withMessages(['targets' => 'Add at least one priority target before publishing.']);
            }
            $activePriorityIds = Priority::query()->active()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $targetPriorityIds = $targets->pluck('priority_id')->map(fn (mixed $id): int => (int) $id)->all();
            $missingPriorityIds = array_diff($activePriorityIds, $targetPriorityIds);
            if ($missingPriorityIds !== []) {
                throw ValidationException::withMessages(['targets' => 'Every enabled priority must have a target in this policy.']);
            }
            $targetData = array_values($targets->map(fn ($target): array => [
                'priority_id' => (int) $target->priority_id,
                'target_seconds' => (int) $target->target_seconds,
            ])->all());
            $this->validateTargetInput($calendar, $targetData);

            $replacedPolicyId = $settings->current_sla_policy_id;

            $locked->forceFill([
                'publication_state' => PublicationState::Published,
                'published_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();
            $settings->forceFill([
                'current_sla_policy_id' => $locked->getKey(),
                'updated_by' => $actor->getKey(),
                'lock_version' => $settings->lock_version + 1,
            ])->save();

            $this->recordActivity($locked, $actor, 'sla_policy.published', [
                'version_no' => $locked->version_no,
                'calendar_version_id' => $calendar->getKey(),
                'target_count' => $targets->count(),
                'replaced_policy_id' => $replacedPolicyId,
            ]);
        }, attempts: 3);
    }

    public function setActive(SlaPolicy $policy, bool $isActive, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($policy, $isActive, $actor): void {
            $settings = $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = SlaPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureNotCurrent($locked, $settings);
            if ($locked->is_active === $isActive) {
                return;
            }

            $locked->forceFill(['is_active' => $isActive, 'updated_by' => $actor->getKey()])->save();
            $this->recordActivity($locked, $actor, $isActive ? 'sla_policy.enabled' : 'sla_policy.disabled', ['is_active' => $isActive]);
        }, attempts: 3);
    }

    public function archive(SlaPolicy $policy, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($policy, $actor): void {
            $settings = $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $locked = SlaPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureNotCurrent($locked, $settings);
            $locked->forceFill(['is_active' => false, 'updated_by' => $actor->getKey(), 'deleted_by' => $actor->getKey()])->save();
            $locked->delete();
            $this->recordActivity($locked, $actor, 'sla_policy.archived', ['is_active' => false]);
        }, attempts: 3);
    }

    public function restore(int $policyId, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($policyId, $actor): void {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $policy = SlaPolicy::withTrashed()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if (! $policy->trashed()) {
                return;
            }

            $policy->restore();
            $policy->forceFill(['is_active' => false, 'updated_by' => $actor->getKey(), 'deleted_by' => null])->save();
            $this->recordActivity($policy, $actor, 'sla_policy.restored', ['is_active' => false]);
        }, attempts: 3);
    }

    /** @param list<int> $policyIds
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function bulk(User $actor, SlaPolicyBulkAction $action, array $policyIds): array
    {
        $this->authorizeManagement($actor);

        return $this->bulkOperations->execute(
            $actor,
            'sla.manage',
            $policyIds,
            function (int $policyId) use ($action, $actor): void {
                $policy = SlaPolicy::withTrashed()->findOrFail($policyId);
                match ($action) {
                    SlaPolicyBulkAction::Enable => $this->setActive($policy, true, $actor),
                    SlaPolicyBulkAction::Disable => $this->setActive($policy, false, $actor),
                    SlaPolicyBulkAction::Archive => $this->archive($policy, $actor),
                    SlaPolicyBulkAction::Restore => $this->restore($policyId, $actor),
                };
            },
        );
    }

    private function lockSettings(): TicketingSetting
    {
        return TicketingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    private function lockAndAuthorizeActor(User $actor): User
    {
        $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        $this->authorizeManagement($lockedActor);

        return $lockedActor;
    }

    private function ensureDraft(SlaPolicy $policy): void
    {
        if ($policy->publication_state !== PublicationState::Draft) {
            throw ValidationException::withMessages(['policy' => 'Published SLA policy versions are immutable. Clone this version to make changes.']);
        }
    }

    /** @param list<array{priority_id: int, target_seconds: int}> $targets */
    private function validateTargetInput(CalendarVersion $calendar, array $targets): void
    {
        $windows = $calendar->windows()->get(['opens_at', 'closes_at']);
        if ($windows->isEmpty()) {
            throw ValidationException::withMessages(['calendar_version_id' => 'The selected calendar must contain at least one business-hours window.']);
        }
        $weeklySeconds = 0;
        foreach ($windows as $window) {
            $weeklySeconds += $this->timeSeconds((string) $window->closes_at) - $this->timeSeconds((string) $window->opens_at);
        }
        $averageDaySeconds = intdiv($weeklySeconds, $windows->count());
        $maximumTargetSeconds = $averageDaySeconds * 365;
        foreach ($targets as $target) {
            if ($target['target_seconds'] < 60 || $target['target_seconds'] > $maximumTargetSeconds) {
                throw ValidationException::withMessages([
                    'targets' => 'Each target must be at least one minute and no more than 365 configured business days.',
                ]);
            }
            if (! Priority::withTrashed()->whereKey($target['priority_id'])->exists()) {
                throw ValidationException::withMessages(['targets' => 'A selected priority could not be found.']);
            }
        }
    }

    /** @param list<array{priority_id: int, target_seconds: int}> $targets */
    private function ensureArchivedTargetsAreRetained(SlaPolicy $policy, array $targets): void
    {
        $existingIds = $policy->targets()->pluck('priority_id')->map(fn (mixed $id): int => (int) $id)->all();
        foreach ($targets as $target) {
            $priority = Priority::withTrashed()->whereKey($target['priority_id'])->firstOrFail();
            if ($priority->trashed() && ! in_array((int) $priority->getKey(), $existingIds, true)) {
                throw ValidationException::withMessages(['targets' => 'An archived priority cannot be added to a new SLA policy version.']);
            }
        }
    }

    private function timeSeconds(string $time): int
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return ($hour * 3600) + ($minute * 60) + $second;
    }

    private function ensureNotCurrent(SlaPolicy $policy, TicketingSetting $settings): void
    {
        if ((int) $settings->current_sla_policy_id === (int) $policy->getKey()) {
            throw ValidationException::withMessages(['policy' => 'The current SLA policy cannot be disabled or archived until a replacement is published.']);
        }
    }

    private function authorizeManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'sla.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(SlaPolicy $policy, User $actor, string $action, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => $action,
            'visibility' => MessageVisibility::Internal,
            'subject_type' => SlaPolicy::class,
            'subject_id' => $policy->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
