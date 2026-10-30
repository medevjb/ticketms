<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\Authorization\RoleLifecycleService;
use App\Services\Authorization\UserLifecycleService;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use HasinHayder\TyroLogin\Helpers\TwoFactorHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
});

test('registered role permissions authorize only active users and active roles', function () {
    $user = User::factory()->create();
    $role = Role::query()->where('name', 'agent')->firstOrFail();
    $user->assignRole($role);

    expect(Gate::forUser($user)->allows('tickets.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view'))->toBeFalse()
        ->and($user->tyroRoleSlugs())->toBe(['agent']);

    $role->forceFill(['is_active' => false])->save();

    expect(Gate::forUser($user->fresh())->allows('tickets.view'))->toBeFalse()
        ->and($user->fresh()->tyroRoleSlugs())->toBe([]);
});

test('archived users and unregistered or direct grants never become effective', function () {
    $user = User::factory()->create();
    $role = Role::query()->where('name', 'customer')->firstOrFail();
    $user->assignRole($role);
    $directPermission = Permission::query()->where('name', 'tickets.assign')->firstOrFail();
    $user->givePermissionTo($directPermission);

    expect(Gate::forUser($user)->allows('tickets.assign'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('unregistered.permission'))->toBeFalse()
        ->and(app(EffectivePermissionService::class)->permissionsFor($user))->not->toContain('tickets.assign');

    $user->delete();

    expect(Gate::forUser($user)->allows('tickets.view'))->toBeFalse()
        ->and($user->tyroRoleSlugs())->toBe([]);
});

test('permission gate interception leaves ordinary policy abilities to Laravel', function () {
    Gate::define('view', fn (User $user): bool => true);
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('view'))->toBeTrue();
});

test('registered customer accounts receive only the customer role', function () {
    $user = User::factory()->create();
    event(new Registered($user));
    $user->refresh();

    expect($user->tyroRoleSlugs())->toBe(['customer'])
        ->and($user->hasRole('admin'))->toBeFalse();
});

test('customer capabilities stay outside Spatie permission assignments', function () {
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    expect($customer->roles()->firstOrFail()->permissions)->toBeEmpty()
        ->and(Gate::forUser($customer)->allows('tickets.create'))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('roles.manage'))->toBeFalse();
});

test('administrator role management cannot list or mutate the customer account marker', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customerRole = Role::query()->where('name', 'customer')->firstOrFail();

    $this->actingAs($administrator)->get(route('dashboard.access.roles'))
        ->assertOk()
        ->assertViewHas('roles', fn ($roles): bool => ! $roles->getCollection()->contains('name', 'customer'));

    expect(fn () => app(RoleLifecycleService::class)->setActive($customerRole, false, $administrator))
        ->toThrow(AuthorizationException::class);
});

test('public registration ignores privilege input and requires email verification', function () {
    Mail::fake();

    $this->post('/register', [
        'name' => 'New Customer',
        'email' => 'new-customer@example.test',
        'password' => 'CorrectHorseBattery1!',
        'password_confirmation' => 'CorrectHorseBattery1!',
        'role' => 'admin',
        'is_active' => true,
        'permissions' => ['roles.manage'],
    ])->assertRedirect(route('tyro-login.verification.notice'));

    $user = User::query()->where('email', 'new-customer@example.test')->firstOrFail();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->tyroRoleSlugs())->toBe(['customer'])
        ->and(Gate::forUser($user)->allows('roles.manage'))->toBeFalse();
});

test('system administrator role cannot be deactivated', function () {
    $administratorRole = Role::query()->where('name', 'admin')->firstOrFail();
    $administrator = User::factory()->create();
    $administrator->assignRole($administratorRole);

    expect(fn () => app(RoleLifecycleService::class)->setActive($administratorRole, false, $administrator))
        ->toThrow(ValidationException::class);

    expect($administratorRole->fresh()->is_active)->toBeTrue();
});

test('administrator can disable another administrator only while an active administrator remains', function () {
    $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $actor->assignRole($adminRole);
    $target->assignRole($adminRole);

    app(UserLifecycleService::class)->setActive($target, false, $actor);

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(Gate::forUser($actor->fresh())->allows('roles.manage'))->toBeTrue();
});

test('archived roles detach memberships and restore without granting them again', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $role = Role::factory()->create();
    $role->givePermissionTo(Permission::query()->where('name', 'tickets.view')->firstOrFail());
    $member = User::factory()->create();
    $member->assignRole($role);

    $lifecycle = app(RoleLifecycleService::class);
    $lifecycle->archive($role, $administrator);

    expect($member->fresh()->tyroRoleSlugs())->toBe([])
        ->and($member->fresh()->authorization_version)->toBe(2);

    $lifecycle->restore($role, $administrator);

    expect($role->fresh()->is_active)->toBeFalse()
        ->and($member->fresh()->tyroRoleSlugs())->toBe([]);
});

test('user lifecycle protects the final administrator and prevents self-disable', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    expect(fn () => app(UserLifecycleService::class)->setActive($administrator, false, $administrator))
        ->toThrow(ValidationException::class);

    expect($administrator->fresh()->is_active)->toBeTrue();
});

test('custom roles cannot gain administrative user management by receiving its permission', function () {
    $customRole = Role::factory()->create();
    $customRole->givePermissionTo(Permission::query()->where('name', 'users.manage')->firstOrFail());
    $customActor = User::factory()->create();
    $customActor->assignRole($customRole);
    $customer = User::factory()->create();

    expect(Gate::forUser($customActor)->allows('users.manage'))->toBeTrue()
        ->and(fn () => app(UserLifecycleService::class)->setActive($customer, false, $customActor))
        ->toThrow(AuthorizationException::class);
});

test('user lifecycle rechecks a stale administrator after acquiring lifecycle locks', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $staleActor = $administrator;
    $customer = User::factory()->create();

    $administrator->forceFill(['is_active' => false])->save();

    expect(fn () => app(UserLifecycleService::class)->setActive($customer, false, $staleActor))
        ->toThrow(AuthorizationException::class);

    expect($customer->fresh()->is_active)->toBeTrue();
});

test('user lifecycle detaches role grants on archive and restores accounts inactive', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $lifecycle = app(UserLifecycleService::class);
    $lifecycle->archive($customer, $administrator);

    expect($customer->fresh()->trashed())->toBeTrue()
        ->and($customer->fresh()->authorization_version)->toBe(2);

    $lifecycle->restore($customer, $administrator);

    expect($customer->fresh()->is_active)->toBeFalse()
        ->and($customer->fresh()->tyroRoleSlugs())->toBe([]);
});

test('native Tyro authorization management URLs are denied directly', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    'users' => '/dashboard/users',
    'roles' => '/dashboard/roles',
    'privileges' => '/dashboard/privileges',
]);

test('disabled accounts lose an existing dashboard session', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->get('/dashboard/profile')
        ->assertRedirect(route('tyro-login.login'));

    expect(auth()->check())->toBeFalse();
});

test('disabled accounts cannot authenticate through Tyro Login', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

test('Tyro dashboard shell accepts the configured role adapters', function (string $roleName) {
    $user = User::factory()->create();
    $role = Role::query()->where('name', $roleName)->firstOrFail();
    $user->assignRole($role);

    $this->actingAs($user)->get('/dashboard')->assertOk();
})->with([
    'administrator' => ['admin'],
    'agent' => ['agent'],
]);

test('customers use the separate portal instead of Tyro dashboard pages', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('customer.dashboard'));
    $this->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee('My requests')
        ->assertSee('Updates')
        ->assertDontSee('Administration');
    $this->get(route('dashboard.tickets.index'))->assertNotFound();
    $this->get('/dashboard/profile')->assertNotFound();
});

test('agent pages show only business navigation for their role', function (string $roleName, string $ticketLabel, string $analyticsLabel) {
    $user = User::factory()->create();
    $user->assignRole(Role::query()->where('name', $roleName)->firstOrFail());

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee($ticketLabel)
        ->assertSee($analyticsLabel)
        ->assertSee('Notifications')
        ->assertDontSee('Administration')
        ->assertDontSee('Ticket imports')
        ->assertDontSee('System Settings')
        ->assertDontSee('Media Library')
        ->assertDontSee('Dashboard Components');

    $this->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee($ticketLabel)
        ->assertDontSee('Administration')
        ->assertDontSee('Ticket imports')
        ->assertDontSee('System Settings');
})->with([
    'agent' => ['agent', 'Assigned tickets', 'My performance'],
]);

test('administrator retains administration navigation on ticket pages', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());

    $this->actingAs($administrator)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Administration')
        ->assertSee('Categories')
        ->assertSee('Ticket imports');
});

test('landing page is public and sends signed-in users to their role-specific dashboard', function () {
    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('auth.user', null));

    $user = User::factory()->create();
    $user->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('customer.dashboard'));
});

test('successful login sends every configured role to the role-aware Tyro dashboard', function (string $roleName) {
    config(['tyro-login.two_factor.enabled' => false]);

    $user = User::factory()->create();
    $user->assignRole(Role::query()->where('name', $roleName)->firstOrFail());

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    if ($roleName === 'customer') {
        $this->get('/dashboard')->assertRedirect(route('customer.dashboard'));
    } else {
        $this->get('/dashboard')->assertOk();
    }
})->with([
    'administrator' => ['admin'],
    'agent' => ['agent'],
    'customer' => ['customer'],
]);

test('Tyro protects system settings with the Spatie-backed administrator role adapter', function (string $roleName, int $status) {
    $user = User::factory()->create();
    $role = Role::query()->where('name', $roleName)->firstOrFail();
    $user->assignRole($role);

    $this->actingAs($user)->get('/dashboard/settings/system')->assertStatus($status);
})->with([
    'administrator access' => ['admin', 200],
    'agent denied settings' => ['agent', 302],
    'customer portal is isolated from settings' => ['customer', 404],
]);

test('Tyro login configuration delegates registration and staff two-factor policy to application roles', function () {
    expect(config('tyro-login.tyro.assign_default_role'))->toBeFalse()
        ->and(config('tyro-login.registration.require_email_verification'))->toBeTrue()
        ->and(config('tyro-login.two_factor.enabled'))->toBeTrue()
        ->and(config('tyro-login.two_factor.forced_roles'))->toBe('admin,agent')
        ->and(config('tyro-login.two_factor.skip_roles'))->toBe('customer');
});

test('staff require two-factor setup while customers may skip enrollment', function () {
    $staff = User::factory()->create();
    $staff->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());

    expect(TwoFactorHelper::userHasForcedRole($staff))->toBeTrue()
        ->and(TwoFactorHelper::userShouldSkipTwoFactorSetup($staff))->toBeFalse()
        ->and(TwoFactorHelper::userShouldSkipTwoFactorSetup($customer))->toBeTrue();
});
