<?php

namespace App\Services\Priorities;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Enums\PriorityBulkAction;
use App\Models\ActivityEntry;
use App\Models\Priority;
use App\Models\TicketingSetting;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\BulkOperations\BulkOperationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PriorityService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly BulkOperationService $bulkOperations,
    ) {}

    /** @param array{search?: string|null, status?: string|null} $filters
     * @return LengthAwarePaginator<int, Priority>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeManagement($actor);
        $status = in_array($filters['status'] ?? 'active', ['all', 'active', 'inactive', 'archived'], true)
            ? ($filters['status'] ?? 'active')
            : 'active';
        $query = Priority::query();

        if (in_array($status, ['all', 'archived'], true)) {
            $query->withTrashed();
        }
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'archived') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('sort_order')->orderBy('id')->paginate(15);
    }

    public function create(User $actor, string $name, string $slug, int $sortOrder): Priority
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($actor, $name, $slug, $sortOrder): Priority {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $priority = Priority::query()->create([
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ]);
            $priority->forceFill(['created_by' => $actor->getKey(), 'is_active' => false])->save();
            $this->recordActivity($priority, $actor, 'priority.created', [
                'name' => $priority->name,
                'slug' => $priority->slug,
                'sort_order' => $priority->sort_order,
                'is_active' => false,
            ]);

            return $priority;
        }, attempts: 3);
    }

    public function update(Priority $priority, User $actor, string $name, int $sortOrder): Priority
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($priority, $actor, $name, $sortOrder): Priority {
            $this->lockAndAuthorizeActor($actor);
            $locked = Priority::query()->whereKey($priority->getKey())->lockForUpdate()->firstOrFail();
            $before = ['name' => $locked->name, 'sort_order' => $locked->sort_order];
            $locked->fill(['name' => $name, 'sort_order' => $sortOrder]);
            $locked->forceFill(['updated_by' => $actor->getKey()])->save();

            if ($locked->wasChanged(['name', 'sort_order'])) {
                $this->recordActivity($locked, $actor, 'priority.updated', [
                    'before' => $before,
                    'after' => ['name' => $locked->name, 'sort_order' => $locked->sort_order],
                ]);
            }

            return $locked;
        }, attempts: 3);
    }

    public function setActive(Priority $priority, bool $isActive, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($priority, $isActive, $actor): void {
            $settings = $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $lockedPriorities = Priority::query()->active()->orderBy('id')->lockForUpdate()->get();
            $locked = $lockedPriorities->firstWhere('id', $priority->getKey());

            if (! $isActive && $lockedPriorities->count() <= 1 && $locked !== null) {
                throw ValidationException::withMessages(['priority' => 'At least one active priority must remain available.']);
            }

            $locked ??= Priority::query()->whereKey($priority->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->is_active === $isActive) {
                return;
            }

            if ($isActive && ! DB::table('sla_policy_targets')
                ->where('sla_policy_id', $settings->current_sla_policy_id)
                ->where('priority_id', $locked->getKey())
                ->exists()) {
                throw ValidationException::withMessages([
                    'priority' => 'Publish a current SLA policy target for this priority before enabling it.',
                ]);
            }

            $locked->forceFill(['is_active' => $isActive, 'updated_by' => $actor->getKey()])->save();
            $this->recordActivity($locked, $actor, $isActive ? 'priority.enabled' : 'priority.disabled', ['is_active' => $isActive]);
        }, attempts: 3);
    }

    public function archive(Priority $priority, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($priority, $actor): void {
            $this->lockSettings();
            $this->lockAndAuthorizeActor($actor);
            $lockedPriorities = Priority::query()->active()->orderBy('id')->lockForUpdate()->get();
            $locked = $lockedPriorities->firstWhere('id', $priority->getKey());

            if ($lockedPriorities->count() <= 1 && $locked !== null) {
                throw ValidationException::withMessages(['priority' => 'The last active priority cannot be archived.']);
            }

            $locked ??= Priority::query()->whereKey($priority->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
                'deleted_by' => $actor->getKey(),
            ])->save();
            $locked->delete();
            $this->recordActivity($locked, $actor, 'priority.archived', ['is_active' => false]);
        }, attempts: 3);
    }

    public function restore(int $priorityId, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($priorityId, $actor): void {
            $this->lockAndAuthorizeActor($actor);
            $priority = Priority::withTrashed()->whereKey($priorityId)->lockForUpdate()->firstOrFail();
            if (! $priority->trashed()) {
                return;
            }

            $priority->restore();
            $priority->forceFill(['is_active' => false, 'updated_by' => $actor->getKey(), 'deleted_by' => null])->save();
            $this->recordActivity($priority, $actor, 'priority.restored', ['is_active' => false]);
        }, attempts: 3);
    }

    /** @param list<int> $priorityIds
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function bulk(User $actor, PriorityBulkAction $action, array $priorityIds): array
    {
        $this->authorizeManagement($actor);

        return $this->bulkOperations->execute(
            $actor,
            'priorities.manage',
            $priorityIds,
            function (int $priorityId) use ($action, $actor): void {
                $priority = Priority::withTrashed()->findOrFail($priorityId);
                match ($action) {
                    PriorityBulkAction::Enable => $this->setActive($priority, true, $actor),
                    PriorityBulkAction::Disable => $this->setActive($priority, false, $actor),
                    PriorityBulkAction::Archive => $this->archive($priority, $actor),
                    PriorityBulkAction::Restore => $this->restore($priorityId, $actor),
                };
            },
        );
    }

    private function lockAndAuthorizeActor(User $actor): User
    {
        $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        $this->authorizeManagement($lockedActor);

        return $lockedActor;
    }

    private function lockSettings(): TicketingSetting
    {
        return TicketingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    private function authorizeManagement(User $actor): void
    {
        if (! in_array('admin', $actor->tyroRoleSlugs(), true) || ! $this->permissions->allows($actor, 'priorities.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(Priority $priority, User $actor, string $action, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => $action,
            'visibility' => MessageVisibility::Internal,
            'subject_type' => Priority::class,
            'subject_id' => $priority->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
