<?php

use App\Models\ActivityEntry;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
});

it('allows administrators to browse categories and denies non-administrative roles', function (): void {
    $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

    $this->actingAs($this->admin)
        ->get(route('dashboard.categories.index'))
        ->assertOk()
        ->assertSee('Billing')
        ->assertSee('billing');
    $this->get(route('dashboard.categories.create'))->assertRedirectToRoute('dashboard.categories.index', ['create' => 1]);
    $this->get(route('dashboard.categories.index', ['create' => 1]))->assertOk()->assertSee('id="category-editor"', false)->assertSee('Create category');
    $this->get(route('dashboard.categories.edit', $category))->assertRedirectToRoute('dashboard.categories.index', ['edit' => $category->id]);
    $this->get(route('dashboard.categories.index', ['edit' => $category->id]))->assertOk()->assertSee('Edit category');

    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($agent)->get(route('dashboard.categories.index'))->assertRedirect();
});

it('creates a category with a normalized slug and ignores unvalidated lifecycle input', function (): void {
    $this->actingAs($this->admin)->post(route('dashboard.categories.store'), [
        'name' => 'Customer Support',
        'slug' => '  Customer Support !! ',
        'description' => 'Help with account questions.',
        'is_active' => false,
        'deleted_by' => 999,
    ])->assertRedirect(route('dashboard.categories.index'));

    $category = Category::query()->where('slug', 'customer-support')->firstOrFail();
    expect($category->is_active)->toBeTrue()
        ->and($category->created_by)->toBe($this->admin->id)
        ->and($category->deleted_by)->toBeNull();

    $this->assertDatabaseHas('activity_entries', [
        'action' => 'category.created',
        'subject_type' => Category::class,
        'subject_id' => $category->id,
        'actor_id' => $this->admin->id,
    ]);
});

it('reserves slugs after archive and prevents changing stable slugs during edit', function (): void {
    $category = Category::factory()->create(['slug' => 'billing']);
    $category->delete();

    $this->actingAs($this->admin)->post(route('dashboard.categories.store'), [
        'name' => 'Billing New',
        'slug' => 'billing',
    ])->assertSessionHasErrors('slug');

    $category->restore();
    $this->actingAs($this->admin)->put(route('dashboard.categories.update', $category), [
        'name' => 'Accounts',
        'description' => 'Account questions.',
        'slug' => 'attacker-controlled',
        'is_active' => false,
    ])->assertRedirect(route('dashboard.categories.index'));

    expect($category->fresh()->slug)->toBe('billing')
        ->and($category->fresh()->is_active)->toBeTrue()
        ->and($category->fresh()->name)->toBe('Accounts');
});

it('disables, archives and restores a category inactive while keeping its audit history', function (): void {
    $category = Category::factory()->create();
    $this->actingAs($this->admin)
        ->patch(route('dashboard.categories.availability', $category), ['is_active' => false])
        ->assertRedirect();
    expect($category->fresh()->is_active)->toBeFalse();

    $this->post(route('dashboard.categories.archive', $category))->assertRedirect();
    expect(Category::withTrashed()->findOrFail($category->id)->trashed())->toBeTrue();

    $this->post(route('dashboard.categories.restore', $category->id))->assertRedirect();
    $category->refresh();
    expect($category->trashed())->toBeFalse()->and($category->is_active)->toBeFalse();

    expect(ActivityEntry::query()->where('subject_type', Category::class)
        ->where('subject_id', $category->id)
        ->pluck('action')->all())->toContain('category.disabled', 'category.archived', 'category.restored');
});

it('reports successful and failed category bulk actions without losing successful changes', function (): void {
    $enabled = Category::factory()->create(['is_active' => true]);
    $archived = Category::factory()->create(['is_active' => false]);
    $archived->delete();

    $payload = [
        'action' => 'disable',
        'selected_ids' => [$enabled->id, $archived->id],
    ];
    $this->actingAs($this->admin)->post(route('dashboard.categories.bulk'), $payload)
        ->assertRedirect()->assertSessionHas('success', 'Bulk action completed for 1 categories.')
        ->assertSessionHas('bulk_failures');
    $this->post(route('dashboard.categories.bulk'), $payload)->assertRedirect();

    expect($enabled->fresh()->is_active)->toBeFalse()
        ->and(Category::withTrashed()->findOrFail($archived->id)->trashed())->toBeTrue();
});

it('rejects category management from a custom role even when it has the registered permission', function (): void {
    $customRole = Role::factory()->create();
    $customRole->givePermissionTo('categories.manage');
    $customUser = User::factory()->create();
    $customUser->assignRole($customRole);

    $this->actingAs($customUser)->get(route('dashboard.categories.index'))->assertRedirect();
});

it('reopens the category modal with submitted values after validation fails', function (): void {
    $this->actingAs($this->admin)->from(route('dashboard.categories.index'))->post(route('dashboard.categories.store'), [
        'category_form' => 1, 'category_modal_id' => '', 'name' => '', 'slug' => 'custom-slug', 'slug_mode' => 'custom', 'description' => 'Preserve this input.',
    ])->assertRedirectToRoute('dashboard.categories.index');

    $this->get(route('dashboard.categories.index'))->assertOk()
        ->assertSee('id="category-editor"', false)->assertSee('custom-slug')->assertSee('Preserve this input.')
        ->assertViewHas('openCategoryEditor', true);
});
