<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

it('moves legacy super-admin grants and memberships to admin before removing the role', function (): void {
    $admin = Role::query()->where('name', 'admin')->firstOrFail();
    $legacySuperAdmin = new Role;
    $legacySuperAdmin->forceFill([
        'name' => 'super-admin',
        'guard_name' => 'web',
        'is_system' => true,
        'is_active' => true,
    ])->save();
    $legacySuperAdmin->givePermissionTo(Permission::query()->where('name', 'tickets.view')->firstOrFail());

    $legacyOnlyMember = User::factory()->create();
    $overlappingMember = User::factory()->create();
    $legacyOnlyMember->assignRole($legacySuperAdmin);
    $overlappingMember->assignRole([$admin, $legacySuperAdmin]);

    $migration = require database_path('migrations/2026_10_29_072237_consolidate_super_admin_role_into_admin.php');
    $migration->up();

    expect(Role::query()->where('name', 'super-admin')->exists())->toBeFalse()
        ->and($legacyOnlyMember->fresh()->tyroRoleSlugs())->toBe(['admin'])
        ->and($overlappingMember->fresh()->tyroRoleSlugs())->toBe(['admin'])
        ->and($legacyOnlyMember->fresh()->authorization_version)->toBe(2)
        ->and($overlappingMember->fresh()->authorization_version)->toBe(2)
        ->and(DB::table('access_model_has_roles')->where('role_id', $admin->id)->where('model_id', $overlappingMember->id)->count())->toBe(1)
        ->and($admin->fresh()->hasPermissionTo('tickets.view'))->toBeTrue();
});

it('seeds one administrator role with the complete application permission set', function (): void {
    expect(Role::query()->where('name', 'super-admin')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'admin')->count())->toBe(1)
        ->and(Role::query()->where('name', 'admin')->firstOrFail()->permissions)->toHaveCount(39)
        ->and(config('tyro-dashboard.admin_roles'))->toBe(['admin']);
});
