<?php

use App\Models\ActivityEntry;
use App\Models\Priority;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
});

it('allows only administrators to manage priorities and renders management pages', function (): void {
    $priority = Priority::factory()->create(['name' => 'Urgent', 'slug' => 'urgent']);

    $this->actingAs($this->admin)
        ->get(route('dashboard.priorities.index'))
        ->assertOk()
        ->assertSee('Urgent');
    $this->get(route('dashboard.priorities.create'))->assertOk()->assertSee('Create priority');
    $this->get(route('dashboard.priorities.edit', $priority))->assertOk()->assertSee('Edit priority');

    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());
    $this->actingAs($agent)->get(route('dashboard.priorities.index'))->assertRedirect();
});

it('creates priorities disabled with normalized reserved slugs and ignores forged lifecycle input', function (): void {
    $this->actingAs($this->admin)->post(route('dashboard.priorities.store'), [
        'name' => 'Very Urgent',
        'slug' => ' Very Urgent!! ',
        'sort_order' => 1,
        'is_active' => true,
        'deleted_by' => 999,
    ])->assertRedirect(route('dashboard.priorities.index', ['status' => 'inactive']));

    $priority = Priority::query()->where('slug', 'very-urgent')->firstOrFail();
    expect($priority->is_active)->toBeFalse()->and($priority->created_by)->toBe($this->admin->id);
    $this->assertDatabaseHas('activity_entries', [
        'action' => 'priority.created',
        'subject_type' => Priority::class,
        'subject_id' => $priority->id,
    ]);

    $priority->delete();
    $this->post(route('dashboard.priorities.store'), [
        'name' => 'Very Urgent Again',
        'slug' => 'very-urgent',
        'sort_order' => 2,
    ])->assertSessionHasErrors('slug');
});

it('keeps priority slug stable and writes activity for ordered edits', function (): void {
    $priority = Priority::factory()->create(['slug' => 'high-edit-test']);

    $this->actingAs($this->admin)->put(route('dashboard.priorities.update', $priority), [
        'name' => 'High Priority',
        'sort_order' => 1,
        'slug' => 'tampered',
        'is_active' => false,
    ])->assertRedirect(route('dashboard.priorities.index'));

    expect($priority->fresh()->slug)->toBe('high-edit-test')
        ->and($priority->fresh()->sort_order)->toBe(1)
        ->and($priority->fresh()->is_active)->toBeTrue()
        ->and(ActivityEntry::query()->where('subject_type', Priority::class)->where('subject_id', $priority->id)->where('action', 'priority.updated')->exists())->toBeTrue();
});

it('disables, archives and restores priorities disabled with history intact', function (): void {
    $priority = Priority::factory()->create();

    $this->actingAs($this->admin)->patch(route('dashboard.priorities.availability', $priority), ['is_active' => false])->assertRedirect();
    $this->post(route('dashboard.priorities.archive', $priority))->assertRedirect();
    expect(Priority::withTrashed()->findOrFail($priority->id)->trashed())->toBeTrue();

    $this->post(route('dashboard.priorities.restore', $priority->id))->assertRedirect();
    expect($priority->fresh()->trashed())->toBeFalse()->and($priority->fresh()->is_active)->toBeFalse();
    expect(ActivityEntry::query()->where('subject_type', Priority::class)->where('subject_id', $priority->id)->pluck('action')->all())
        ->toContain('priority.disabled', 'priority.archived', 'priority.restored');
});

it('keeps at least one enabled priority and reports partial bulk failures', function (): void {
    $priorities = Priority::query()->active()->get();
    $archived = Priority::factory()->create();
    $archived->forceFill(['is_active' => false])->save();
    $archived->delete();
    $this->actingAs($this->admin)->post(route('dashboard.priorities.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'disable',
        'selected_ids' => [$priorities->first()->id, $archived->id],
    ])->assertRedirect()->assertSessionHas('bulk_failures');
    expect(Priority::query()->active()->count())->toBe(3);

    $remainingPriorities = Priority::query()->active()->get();
    foreach ($remainingPriorities->take($remainingPriorities->count() - 1) as $priority) {
        $this->patch(route('dashboard.priorities.availability', $priority), ['is_active' => false])->assertRedirect();
    }
    $lastActive = Priority::query()->active()->firstOrFail();
    $this->patch(route('dashboard.priorities.availability', $lastActive), ['is_active' => false])->assertSessionHasErrors('priority');
    expect(Priority::query()->active()->count())->toBe(1);

    expect(Priority::query()->active()->count())->toBe(1);
});
