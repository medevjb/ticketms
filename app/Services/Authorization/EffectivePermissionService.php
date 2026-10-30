<?php

namespace App\Services\Authorization;

use App\Authorization\CustomerCapabilityRegistry;
use App\Authorization\PermissionRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EffectivePermissionService
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly CustomerCapabilityRegistry $customerCapabilities,
    ) {}

    public function allows(User $user, string $permission): bool
    {
        if (! $this->registry->recognizes($permission) || ! $user->exists || $user->trashed() || ! $user->is_active) {
            return false;
        }

        $roles = $user->tyroRoleSlugs();

        if (in_array('customer', $roles, true) && array_intersect(['admin', 'agent'], $roles) === []) {
            return $user->hasVerifiedEmail() && $this->customerCapabilities->allows($permission);
        }

        return DB::table('access_model_has_roles as model_roles')
            ->join('access_roles as roles', 'roles.id', '=', 'model_roles.role_id')
            ->join('access_role_has_permissions as role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('access_permissions as permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('model_roles.model_type', User::class)
            ->where('model_roles.model_id', $user->getKey())
            ->where('roles.guard_name', 'web')
            ->where('roles.is_active', true)
            ->whereNull('roles.deleted_at')
            ->where('permissions.guard_name', 'web')
            ->where('permissions.name', $permission)
            ->exists();
    }

    /** @return list<string> */
    public function permissionsFor(User $user): array
    {
        if (! $user->exists || $user->trashed() || ! $user->is_active) {
            return [];
        }

        $roles = $user->tyroRoleSlugs();

        if (in_array('customer', $roles, true) && array_intersect(['admin', 'agent'], $roles) === []) {
            return $user->hasVerifiedEmail() ? $this->customerCapabilities->all() : [];
        }

        return array_values(DB::table('access_model_has_roles as model_roles')
            ->join('access_roles as roles', 'roles.id', '=', 'model_roles.role_id')
            ->join('access_role_has_permissions as role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('access_permissions as permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('model_roles.model_type', User::class)
            ->where('model_roles.model_id', $user->getKey())
            ->where('roles.guard_name', 'web')
            ->where('roles.is_active', true)
            ->whereNull('roles.deleted_at')
            ->where('permissions.guard_name', 'web')
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->map(fn (mixed $name): string => (string) $name)
            ->filter(fn (string $name): bool => $this->registry->recognizes($name))
            ->unique()
            ->values()
            ->all());
    }
}
