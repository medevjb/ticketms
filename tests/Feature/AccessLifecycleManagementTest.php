<?php

use App\Models\BulkOperation;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Authorization\UserLifecycleService;
use App\Services\BulkOperations\BulkOperationService;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
});

it('provides app-owned user and role lifecycle pages to administrators only', function (): void {
    $this->actingAs($this->admin)->get(route('dashboard.access.users'))->assertOk()->assertSee('User access');
    $this->get(route('dashboard.access.roles'))->assertOk()->assertSee('Role access');

    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $this->actingAs($agent)->get(route('dashboard.access.users'))->assertRedirect();
    $this->get(route('dashboard.access.roles'))->assertRedirect();
});

it('archives and restores accounts through an idempotent bounded bulk ledger', function (): void {
    $customer = User::factory()->create();
    $customer->assignRole(Role::query()->where('name', 'customer')->firstOrFail());
    $operationKey = (string) Str::uuid();
    $payload = ['operation_key' => $operationKey, 'action' => 'archive', 'selected_ids' => [$customer->id]];

    $this->actingAs($this->admin)->post(route('dashboard.access.users.bulk'), $payload)->assertRedirect();
    $this->post(route('dashboard.access.users.bulk'), $payload)->assertRedirect();
    $archived = User::withTrashed()->findOrFail($customer->id);
    expect($archived->trashed())->toBeTrue()
        ->and($archived->is_active)->toBeFalse()
        ->and($archived->tyroRoleSlugs())->toBe([])
        ->and(BulkOperation::query()->where('operation_key', $operationKey)->value('succeeded_count'))->toBe(1);

    $this->post(route('dashboard.access.users.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'restore',
        'selected_ids' => [$customer->id],
    ])->assertRedirect();
    expect($archived->fresh()->trashed())->toBeFalse()->and($archived->fresh()->is_active)->toBeFalse();
});

it('disables and restores custom roles while protecting system roles', function (): void {
    $customRole = Role::factory()->create(['name' => 'temporary-support']);
    $customer = User::factory()->create();
    $customer->assignRole($customRole);

    $this->actingAs($this->admin)->post(route('dashboard.access.roles.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'disable',
        'selected_ids' => [$customRole->id],
    ])->assertRedirect();
    expect($customRole->fresh()->is_active)->toBeFalse()->and($customer->fresh()->tyroRoleSlugs())->toBe([]);

    $this->post(route('dashboard.access.roles.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'archive',
        'selected_ids' => [$customRole->id],
    ])->assertRedirect();
    $archivedRole = Role::withTrashed()->findOrFail($customRole->id);
    expect($archivedRole->trashed())->toBeTrue();

    $this->post(route('dashboard.access.roles.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'restore',
        'selected_ids' => [$customRole->id],
    ])->assertRedirect();
    expect($archivedRole->fresh()->trashed())->toBeFalse()->and($archivedRole->fresh()->is_active)->toBeFalse();

    $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
    $this->post(route('dashboard.access.roles.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'disable',
        'selected_ids' => [$adminRole->id],
    ])->assertRedirect()->assertSessionHas('bulk_failures');
    expect($adminRole->fresh()->is_active)->toBeTrue();
});

it('stops remaining bulk items when the actor is disabled between item transactions', function (): void {
    $secondAdministrator = User::factory()->create();
    $secondAdministrator->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $firstTicket = Ticket::factory()->create();
    $secondTicket = Ticket::factory()->create();
    $operationKey = (string) Str::uuid();
    $actorWasDisabled = false;

    expect(fn () => app(BulkOperationService::class)->execute(
        $this->admin,
        'tickets.bulk',
        'tickets',
        Ticket::class,
        'disable',
        $operationKey,
        [$firstTicket->getKey(), $secondTicket->getKey()],
        function (int $ticketId) use (&$actorWasDisabled, $secondAdministrator): void {
            Ticket::query()->whereKey($ticketId)->update(['is_active' => false]);

            if (! $actorWasDisabled) {
                app(UserLifecycleService::class)->setActive($this->admin, false, $secondAdministrator);
                $actorWasDisabled = true;
            }
        },
    ))->toThrow(AuthorizationException::class);

    expect($this->admin->fresh()->is_active)->toBeFalse()
        ->and($firstTicket->fresh()->is_active)->toBeFalse()
        ->and($secondTicket->fresh()->is_active)->toBeTrue()
        ->and(DB::table('bulk_operation_items')->where('subject_id', $firstTicket->getKey())->value('result'))->toBe('succeeded')
        ->and(DB::table('bulk_operation_items')->where('subject_id', $secondTicket->getKey())->value('result'))->toBe('pending');
});
