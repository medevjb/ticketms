<?php

use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
});

it('lets administrators update the single active SLA policy directly', function (): void {
    $policy = SlaPolicy::query()->firstOrFail();
    $targets = $policy->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => (int) ($target->target_seconds / 60) + 60,
    ])->all();

    $this->actingAs($this->admin)
        ->get(route('dashboard.sla-policies.index'))
        ->assertOk()
        ->assertSee('SLA policy')
        ->assertSee('Default resolution SLA')
        ->assertSee('Resolution targets')
        ->assertSee('Business hours');

    $this->put(route('dashboard.sla-policies.update'), [
        'name' => 'Updated resolution targets',
        'warning_percent' => 75,
        'targets' => $targets,
    ])->assertRedirect(route('dashboard.sla-policies.index'));

    expect(SlaPolicy::query()->count())->toBe(1)
        ->and($policy->fresh()->name)->toBe('Updated resolution targets')
        ->and($policy->fresh()->warning_percent)->toBe(75)
        ->and($policy->targets()->orderBy('priority_id')->first()->target_seconds)->toBe($targets[0]['target_minutes'] * 60);
});

it('rejects invalid target values at the request boundary', function (): void {
    $policy = SlaPolicy::query()->firstOrFail();
    $targets = $policy->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => 525601,
    ])->all();

    $this->actingAs($this->admin)->put(route('dashboard.sla-policies.update'), [
        'name' => 'Invalid target policy',
        'warning_percent' => 100,
        'targets' => $targets,
    ])->assertSessionHasErrors(['warning_percent', 'targets.0.target_minutes']);

    expect($policy->fresh()->name)->toBe('Default resolution SLA');
});

it('keeps every active priority target when a submitted value is blank', function (): void {
    $policy = SlaPolicy::query()->firstOrFail();
    $targets = $policy->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => (int) ($target->target_seconds / 60),
    ])->all();
    $targets[0]['target_minutes'] = '';

    $this->actingAs($this->admin)->put(route('dashboard.sla-policies.update'), [
        'name' => 'Should not save',
        'warning_percent' => 75,
        'targets' => $targets,
    ])->assertSessionHasErrors('targets.0.target_minutes');

    expect($policy->fresh()->name)->toBe('Default resolution SLA')
        ->and($policy->targets()->count())->toBe(4);
});

it('keeps every active priority target when a submitted row is missing', function (): void {
    $policy = SlaPolicy::query()->firstOrFail();
    $targets = $policy->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => (int) ($target->target_seconds / 60),
    ])->all();
    array_pop($targets);

    $this->actingAs($this->admin)->put(route('dashboard.sla-policies.update'), [
        'name' => 'Should not save',
        'warning_percent' => 75,
        'targets' => $targets,
    ])->assertSessionHasErrors('targets');

    expect($policy->fresh()->name)->toBe('Default resolution SLA')
        ->and($policy->targets()->count())->toBe(4);
});

it('rejects SLA management from an agent role', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($agent)->get(route('dashboard.sla-policies.index'))->assertRedirect();
});

it('adds a target to the current policy before a new priority can be enabled', function (): void {
    $this->actingAs($this->admin)->post(route('dashboard.priorities.store'), [
        'name' => 'Urgent Plus',
        'slug' => 'urgent-plus',
        'sort_order' => 0,
    ])->assertRedirect();
    $priority = Priority::query()->where('slug', 'urgent-plus')->firstOrFail();

    $this->patch(route('dashboard.priorities.availability', $priority), ['is_active' => true])
        ->assertSessionHasErrors('priority');

    $policy = SlaPolicy::query()->firstOrFail();
    $targets = $policy->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => (int) ($target->target_seconds / 60),
    ])->all();
    $targets[] = ['priority_id' => $priority->id, 'target_minutes' => 120];

    $this->put(route('dashboard.sla-policies.update'), [
        'name' => $policy->name,
        'warning_percent' => $policy->warning_percent,
        'targets' => $targets,
    ])->assertRedirect();
    $this->patch(route('dashboard.priorities.availability', $priority), ['is_active' => true])->assertRedirect();

    expect($priority->fresh()->is_active)->toBeTrue()
        ->and($policy->fresh()->targets()->where('priority_id', $priority->id)->value('target_seconds'))->toBe(7200);
});
