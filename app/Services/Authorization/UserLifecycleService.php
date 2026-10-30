<?php

namespace App\Services\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Services\Tickets\AssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserLifecycleService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly AssignmentService $assignments,
    ) {}

    /** @throws ValidationException */
    public function setActive(User $target, bool $isActive, User $actor): void
    {
        $this->authorizeUserManagement($actor);
        $this->preventSelfLifecycleChange($target, $actor);

        DB::transaction(function () use ($target, $isActive, $actor): void {
            $this->assignments->lockCoordinator();
            $liveTickets = $this->assignments->lockAssignmentsForDeactivation((int) $target->getKey());
            $lockedUsers = $this->lockUsers((int) $actor->getKey(), (int) $target->getKey());
            $lockedActor = $lockedUsers->get((int) $actor->getKey());
            $lockedUser = $lockedUsers->get((int) $target->getKey());
            if ($lockedActor === null || $lockedUser === null) {
                throw new AuthorizationException;
            }
            $this->authorizeUserManagement($lockedActor);
            $administratorRoles = $this->lockAdministratorRoles();

            if ($lockedUser->is_active === $isActive && ($isActive || $liveTickets->isEmpty())) {
                return;
            }

            if (! $isActive) {
                if ($lockedUser->is_active) {
                    $this->ensureAnotherAdministratorRemains($lockedUser, $administratorRoles);
                }
                $this->assignments->unassignForDeactivation($liveTickets, $lockedUser, $lockedActor, 'account_disabled');
            }

            if ($lockedUser->is_active !== $isActive) {
                $lockedUser->forceFill([
                    'is_active' => $isActive,
                    'authorization_version' => $lockedUser->authorization_version + 1,
                    'updated_by' => $lockedActor->getKey(),
                    'remember_token' => null,
                ])->save();
            }
        }, attempts: 3);
    }

    /** @throws ValidationException */
    public function archive(User $target, User $actor): void
    {
        $this->authorizeUserManagement($actor);
        $this->preventSelfLifecycleChange($target, $actor);

        DB::transaction(function () use ($target, $actor): void {
            $this->assignments->lockCoordinator();
            $liveTickets = $this->assignments->lockAssignmentsForDeactivation((int) $target->getKey());
            $lockedUsers = $this->lockUsers((int) $actor->getKey(), (int) $target->getKey());
            $lockedActor = $lockedUsers->get((int) $actor->getKey());
            $lockedUser = $lockedUsers->get((int) $target->getKey());
            if ($lockedActor === null || $lockedUser === null) {
                throw new AuthorizationException;
            }
            $this->authorizeUserManagement($lockedActor);
            $administratorRoles = $this->lockAdministratorRoles();

            $this->ensureAnotherAdministratorRemains($lockedUser, $administratorRoles);
            $this->assignments->unassignForDeactivation($liveTickets, $lockedUser, $lockedActor, 'account_archived');

            $lockedUser->roles()->detach();
            $lockedUser->permissions()->detach();
            $lockedUser->forceFill([
                'is_active' => false,
                'authorization_version' => $lockedUser->authorization_version + 1,
                'updated_by' => $actor->getKey(),
                'deleted_by' => $actor->getKey(),
                'remember_token' => null,
            ])->save();
            $lockedUser->delete();
        }, attempts: 3);
    }

    public function restore(User $target, User $actor): void
    {
        $this->authorizeUserManagement($actor);

        DB::transaction(function () use ($target, $actor): void {
            $this->assignments->lockCoordinator();
            $this->lockAdministratorRoles();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeUserManagement($lockedActor);
            $lockedUser = User::query()
                ->withTrashed()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedUser->trashed()) {
                return;
            }

            $lockedUser->restore();
            $lockedUser->forceFill([
                'is_active' => false,
                'authorization_version' => $lockedUser->authorization_version + 1,
                'updated_by' => $actor->getKey(),
                'remember_token' => null,
            ])->save();
        }, attempts: 3);
    }

    private function authorizeUserManagement(User $actor): void
    {
        $roles = $actor->tyroRoleSlugs();
        $isAdministrator = in_array('admin', $roles, true);

        if (! $isAdministrator || ! $this->permissions->allows($actor, 'users.manage')) {
            throw new AuthorizationException;
        }
    }

    private function preventSelfLifecycleChange(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot disable or archive your own account.',
            ]);
        }
    }

    /** @return Collection<int, User> */
    private function lockUsers(int $firstUserId, int $secondUserId): Collection
    {
        return User::query()
            ->whereIn('id', array_values(array_unique([$firstUserId, $secondUserId])))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /** @return Collection<int, Role> */
    private function lockAdministratorRoles(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'admin')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Role>  $administratorRoles
     *
     * @throws ValidationException
     */
    private function ensureAnotherAdministratorRemains(User $target, Collection $administratorRoles): void
    {
        $targetHasAdministratorRole = $target->roles()
            ->whereIn('access_roles.id', $administratorRoles->modelKeys())
            ->where('access_roles.is_active', true)
            ->exists();

        if (! $targetHasAdministratorRole) {
            return;
        }

        $anotherAdministratorExists = User::query()
            ->active()
            ->where('users.id', '<>', $target->getKey())
            ->whereHas('roles', fn ($query) => $query
                ->whereIn('access_roles.id', $administratorRoles->modelKeys())
                ->where('access_roles.is_active', true)
                ->where('access_roles.guard_name', 'web'))
            ->lockForUpdate()
            ->exists();

        if (! $anotherAdministratorExists) {
            throw ValidationException::withMessages([
                'user' => 'At least one active administrator account must retain administrative access.',
            ]);
        }
    }
}
