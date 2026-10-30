<?php

use App\Enums\PublicationState;
use App\Models\CalendarVersion;
use App\Models\Priority;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\TicketingSetting;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
});

it('clones a policy, edits business-second targets and atomically selects it on publish', function (): void {
    $source = SlaPolicy::query()->where('version_no', 1)->firstOrFail();
    $oldPointer = (int) TicketingSetting::query()->value('current_sla_policy_id');

    $this->actingAs($this->admin)
        ->get(route('dashboard.sla-policies.index'))
        ->assertOk()
        ->assertSee('SLA policies')
        ->assertSee('Default resolution SLA');
    $this->post(route('dashboard.sla-policies.clone'), ['source_policy_id' => $source->id])->assertRedirect();

    $draft = SlaPolicy::query()->where('version_no', 2)->firstOrFail();
    expect($draft->publication_state)->toBe(PublicationState::Draft)
        ->and($draft->targets()->count())->toBe(4);
    $this->get(route('dashboard.sla-policies.edit', $draft))->assertOk()->assertSee('Edit SLA policy draft');

    $targets = $source->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => (int) ($target->target_seconds / 60) + 60,
    ])->all();
    $this->put(route('dashboard.sla-policies.update', $draft), [
        'name' => 'Resolution policy v2',
        'calendar_version_id' => $source->calendar_version_id,
        'warning_percent' => 75,
        'targets' => $targets,
    ])->assertRedirect();

    expect($draft->fresh()->warning_percent)->toBe(75)
        ->and($draft->fresh()->targets()->orderBy('priority_id')->first()->target_seconds)->toBe($targets[0]['target_minutes'] * 60);

    $this->post(route('dashboard.sla-policies.publish', $draft))->assertRedirect();
    expect($draft->fresh()->publication_state)->toBe(PublicationState::Published)
        ->and((int) TicketingSetting::query()->value('current_sla_policy_id'))->toBe($draft->id)
        ->and($source->fresh()->publication_state)->toBe(PublicationState::Published)
        ->and((int) $oldPointer)->not->toBe($draft->id);
});

it('keeps the current policy protected and refuses to publish missing active-priority targets', function (): void {
    $current = SlaPolicy::query()->where('version_no', 1)->firstOrFail();
    $this->actingAs($this->admin)
        ->patch(route('dashboard.sla-policies.availability', $current), ['is_active' => false])
        ->assertSessionHasErrors('policy');
    $this->post(route('dashboard.sla-policies.archive', $current))->assertSessionHasErrors('policy');
    expect($current->fresh()->is_active)->toBeTrue();

    $this->post(route('dashboard.sla-policies.clone'), ['source_policy_id' => $current->id]);
    $draft = SlaPolicy::query()->where('version_no', 2)->firstOrFail();
    $firstTarget = $current->targets()->orderBy('priority_id')->firstOrFail();
    $this->put(route('dashboard.sla-policies.update', $draft), [
        'name' => 'Incomplete policy',
        'calendar_version_id' => $current->calendar_version_id,
        'warning_percent' => 80,
        'targets' => [[
            'priority_id' => $firstTarget->priority_id,
            'target_minutes' => (int) ($firstTarget->target_seconds / 60),
        ]],
    ])->assertRedirect();
    $this->post(route('dashboard.sla-policies.publish', $draft))->assertSessionHasErrors('targets');

    expect((int) TicketingSetting::query()->value('current_sla_policy_id'))->toBe($current->id)
        ->and($draft->fresh()->publication_state)->toBe(PublicationState::Draft);
});

it('rejects inactive priorities or out-of-range SLA targets at the request boundary', function (): void {
    $source = SlaPolicy::query()->where('version_no', 1)->firstOrFail();
    $this->actingAs($this->admin)->post(route('dashboard.sla-policies.clone'), ['source_policy_id' => $source->id]);
    $draft = SlaPolicy::query()->where('version_no', 2)->firstOrFail();
    $targets = $source->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => 525601,
    ])->all();

    $this->put(route('dashboard.sla-policies.update', $draft), [
        'name' => 'Invalid target policy',
        'calendar_version_id' => $source->calendar_version_id,
        'warning_percent' => 100,
        'targets' => $targets,
    ])->assertSessionHasErrors(['warning_percent', 'targets.0.target_minutes']);
});

it('rejects SLA management from an agent role', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($agent)->get(route('dashboard.sla-policies.index'))->assertRedirect();
});

it('requires a published current-policy target before a new priority can be enabled', function (): void {
    $this->actingAs($this->admin)->post(route('dashboard.priorities.store'), [
        'name' => 'Urgent Plus',
        'slug' => 'urgent-plus',
        'sort_order' => 0,
    ])->assertRedirect();
    $priority = Priority::query()->where('slug', 'urgent-plus')->firstOrFail();
    expect($priority->is_active)->toBeFalse();

    $this->patch(route('dashboard.priorities.availability', $priority), ['is_active' => true])
        ->assertSessionHasErrors('priority');

    $currentPolicy = SlaPolicy::query()->where('version_no', 1)->firstOrFail();
    $this->post(route('dashboard.sla-policies.clone'), ['source_policy_id' => $currentPolicy->id])->assertRedirect();
    $draft = SlaPolicy::query()->where('version_no', 2)->firstOrFail();
    $this->get(route('dashboard.sla-policies.edit', $draft))->assertOk()->assertSee('Urgent Plus');

    $targets = $currentPolicy->targets()->orderBy('priority_id')->get()->map(fn ($target): array => [
        'priority_id' => $target->priority_id,
        'target_minutes' => (int) ($target->target_seconds / 60),
    ])->all();
    $targets[] = ['priority_id' => $priority->id, 'target_minutes' => 120];

    $this->put(route('dashboard.sla-policies.update', $draft), [
        'name' => 'Policy including urgent plus',
        'calendar_version_id' => CalendarVersion::query()->where('version_no', 1)->value('id'),
        'warning_percent' => 80,
        'targets' => $targets,
    ])->assertRedirect();
    $this->post(route('dashboard.sla-policies.publish', $draft))->assertRedirect();
    $this->patch(route('dashboard.priorities.availability', $priority), ['is_active' => true])->assertRedirect();

    expect($priority->fresh()->is_active)->toBeTrue()
        ->and($draft->fresh()->targets()->where('priority_id', $priority->id)->value('target_seconds'))->toBe(7200);
});

it('keeps bulk policy archival partial when the current policy is selected', function (): void {
    $current = SlaPolicy::query()->where('version_no', 1)->firstOrFail();
    $this->actingAs($this->admin)->post(route('dashboard.sla-policies.clone'), ['source_policy_id' => $current->id]);
    $draft = SlaPolicy::query()->where('version_no', 2)->firstOrFail();

    $this->post(route('dashboard.sla-policies.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'archive',
        'selected_ids' => [$current->id, $draft->id],
    ])->assertRedirect()->assertSessionHas('bulk_failures');

    expect($current->fresh()->is_active)->toBeTrue()
        ->and(SlaPolicy::withTrashed()->findOrFail($draft->id)->trashed())->toBeTrue()
        ->and((int) TicketingSetting::query()->value('current_sla_policy_id'))->toBe($current->id);
});
