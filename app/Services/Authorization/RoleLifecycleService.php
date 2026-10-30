<?php

namespace App\Services\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Services\Tickets\AssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RoleLifecycleService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly AssignmentService $assignments,
    ) {}

    /**
     * @throws ValidationException
     */
    public function setActive(Role $role, bool $isActive, User $actor): void
    {
        $this->authorizeRoleManagement($actor);
        $this->ensureRoleIsManageable($role);

        DB::transaction(function () use ($role, $isActive, $actor): void {
            $this->assignments->lockCoordinator();
            $administratorRoles = $this->lockAdministratorRoles();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeRoleManagement($lockedActor);

            $lockedRole = Role::query()
                ->whereKey($role->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureRoleIsManageable($lockedRole);

            if ($lockedRole->is_active === $isActive) {
                return;
            }

            if ($lockedRole->is_system && ! $isActive) {
                throw ValidationException::withMessages([
                    'role' => 'System roles cannot be disabled.',
                ]);
            }

            if (! $isActive) {
                $this->ensureAnotherAdministratorRemains($lockedRole, $administratorRoles);
            }

            $lockedRole->forceFill([
                'is_active' => $isActive,
                'updated_by' => $actor->getKey(),
            ])->save();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }, attempts: 3);
    }

    /** @throws ValidationException */
    public function archive(Role $role, User $actor): void
    {
        $this->authorizeRoleManagement($actor);
        $this->ensureRoleIsManageable($role);

        DB::transaction(function () use ($role, $actor): void {
            $this->assignments->lockCoordinator();
            $administratorRoles = $this->lockAdministratorRoles();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeRoleManagement($lockedActor);
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureRoleIsManageable($lockedRole);

            if ($lockedRole->is_system) {
                throw ValidationException::withMessages([
                    'role' => 'System roles cannot be archived.',
                ]);
            }

            $this->ensureAnotherAdministratorRemains($lockedRole, $administratorRoles);

            $userIds = DB::table('access_model_has_roles')
                ->where('role_id', $lockedRole->getKey())
                ->where('model_type', User::class)
                ->pluck('model_id');

            if ($userIds->isNotEmpty()) {
                User::query()
                    ->whereIn('id', $userIds)
                    ->whereNull('deleted_at')
                    ->increment('authorization_version');
            }

            $lockedRole->users()->detach();
            $lockedRole->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
                'deleted_by' => $actor->getKey(),
            ])->save();
            $lockedRole->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }, attempts: 3);
    }

    public function restore(Role $role, User $actor): void
    {
        $this->authorizeRoleManagement($actor);
        $this->ensureRoleIsManageable($role);

        DB::transaction(function () use ($role, $actor): void {
            $this->assignments->lockCoordinator();
            $this->lockAdministratorRoles();
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeRoleManagement($lockedActor);
            $lockedRole = Role::query()
                ->withTrashed()
                ->whereKey($role->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureRoleIsManageable($lockedRole);

            if (! $lockedRole->trashed()) {
                return;
            }

            $lockedRole->restore();
            $lockedRole->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
            ])->save();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }, attempts: 3);
    }

    private function authorizeRoleManagement(User $actor): void
    {
        $roles = $actor->tyroRoleSlugs();
        $isAdministrator = in_array('admin', $roles, true);

        if (! $isAdministrator || ! $this->permissions->allows($actor, 'roles.manage')) {
            throw new AuthorizationException;
        }
    }

    private function ensureRoleIsManageable(Role $role): void
    {
        if ($role->name === 'customer') {
            throw new AuthorizationException;
        }
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
    private function ensureAnotherAdministratorRemains(Role $role, Collection $administratorRoles): void
    {
        $isAdministratorRole = $administratorRoles->contains(
            fn (Role $candidate): bool => $candidate->is($role),
        );

        if (! $isAdministratorRole) {
            return;
        }

        $remainingRoleIds = $administratorRoles
            ->reject(fn (Role $candidate): bool => $candidate->is($role))
            ->pluck('id');

        $remainingAdministrators = User::query()
            ->active()
            ->whereHas('roles', fn ($query) => $query
                ->whereIn('access_roles.id', $remainingRoleIds)
                ->where('access_roles.is_active', true)
                ->where('access_roles.guard_name', 'web'))
            ->lockForUpdate()
            ->count();

        if ($remainingAdministrators === 0) {
            throw ValidationException::withMessages([
                'role' => 'At least one active administrator account must retain administrative access.',
            ]);
        }
    }
}
