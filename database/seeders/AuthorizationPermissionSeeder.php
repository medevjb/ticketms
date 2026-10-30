<?php

namespace Database\Seeders;

use App\Authorization\PermissionRegistry;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthorizationPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registry = app(PermissionRegistry::class);

        DB::transaction(function () use ($registry): void {
            $permissions = collect($registry->permissions())
                ->mapWithKeys(fn (string $name): array => [
                    $name => Permission::query()->firstOrCreate([
                        'name' => $name,
                        'guard_name' => 'web',
                    ]),
                ]);

            foreach ($registry->rolePermissions() as $roleName => $permissionNames) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->firstOrFail();

                $role->syncPermissions($permissions->only($permissionNames)->values());
            }

            Role::query()
                ->where('name', 'customer')
                ->where('guard_name', 'web')
                ->firstOrFail()
                ->syncPermissions([]);
        }, attempts: 3);
    }
}
