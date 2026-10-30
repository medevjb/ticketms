<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $superAdmin = DB::table('access_roles')
                ->where('name', 'super-admin')
                ->where('guard_name', 'web')
                ->lockForUpdate()
                ->first();

            if ($superAdmin === null) {
                return;
            }

            $admin = DB::table('access_roles')
                ->where('name', 'admin')
                ->where('guard_name', 'web')
                ->lockForUpdate()
                ->first();

            if ($admin === null) {
                $affectedUserIds = DB::table('access_model_has_roles')
                    ->where('role_id', $superAdmin->id)
                    ->where('model_type', User::class)
                    ->pluck('model_id')
                    ->unique()
                    ->values();

                DB::table('access_roles')->where('id', $superAdmin->id)->update([
                    'name' => 'admin',
                    'is_active' => true,
                    'is_system' => true,
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);

                if ($affectedUserIds->isNotEmpty()) {
                    DB::table('users')->whereIn('id', $affectedUserIds)->update([
                        'authorization_version' => DB::raw('authorization_version + 1'),
                        'remember_token' => null,
                    ]);
                }

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                return;
            }

            DB::table('access_roles')->where('id', $admin->id)->update([
                'is_active' => true,
                'is_system' => true,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            $permissions = DB::table('access_role_has_permissions')
                ->where('role_id', $superAdmin->id)
                ->pluck('permission_id');

            foreach ($permissions as $permissionId) {
                DB::table('access_role_has_permissions')->insertOrIgnore([
                    'role_id' => $admin->id,
                    'permission_id' => $permissionId,
                ]);
            }

            $memberships = DB::table('access_model_has_roles')
                ->where('role_id', $superAdmin->id)
                ->get(['model_type', 'model_id']);

            foreach ($memberships as $membership) {
                DB::table('access_model_has_roles')->insertOrIgnore([
                    'role_id' => $admin->id,
                    'model_type' => $membership->model_type,
                    'model_id' => $membership->model_id,
                ]);
            }

            $affectedUserIds = $memberships
                ->where('model_type', User::class)
                ->pluck('model_id')
                ->unique()
                ->values();

            if ($affectedUserIds->isNotEmpty()) {
                DB::table('users')->whereIn('id', $affectedUserIds)->update([
                    'authorization_version' => DB::raw('authorization_version + 1'),
                    'remember_token' => null,
                ]);
            }

            DB::table('access_model_has_roles')->where('role_id', $superAdmin->id)->delete();
            DB::table('access_role_has_permissions')->where('role_id', $superAdmin->id)->delete();
            DB::table('access_roles')->where('id', $superAdmin->id)->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }, attempts: 3);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $admin = DB::table('access_roles')
                ->where('name', 'admin')
                ->where('guard_name', 'web')
                ->first();

            if ($admin === null || DB::table('access_roles')->where('name', 'super-admin')->where('guard_name', 'web')->exists()) {
                return;
            }

            $now = now();
            $superAdminId = DB::table('access_roles')->insertGetId([
                'name' => 'super-admin',
                'guard_name' => 'web',
                'is_system' => true,
                'is_active' => true,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
            ]);

            $permissionIds = DB::table('access_role_has_permissions')
                ->where('role_id', $admin->id)
                ->pluck('permission_id');

            foreach ($permissionIds as $permissionId) {
                DB::table('access_role_has_permissions')->insertOrIgnore([
                    'role_id' => $superAdminId,
                    'permission_id' => $permissionId,
                ]);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }, attempts: 3);
    }
};
