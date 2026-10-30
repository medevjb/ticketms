<?php

use App\Enums\PublicationState;
use App\Models\CalendarVersion;
use App\Models\Role;
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

it('lets administrators clone, edit and publish immutable calendar versions', function (): void {
    $source = CalendarVersion::query()->where('version_no', 1)->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('dashboard.calendars.index'))
        ->assertOk()
        ->assertSee('Business calendars')
        ->assertSee('Default support calendar');

    $this->post(route('dashboard.calendars.clone'), ['source_calendar_id' => $source->id])->assertRedirect();
    $draft = CalendarVersion::query()->where('version_no', 2)->firstOrFail();
    expect($draft->publication_state)->toBe(PublicationState::Draft)
        ->and($draft->timezone)->toBe('Asia/Dhaka')
        ->and($draft->windows()->count())->toBe(5);
    $this->get(route('dashboard.calendars.edit', $draft))->assertOk()->assertSee('Edit calendar draft');

    $this->put(route('dashboard.calendars.update', $draft), [
        'name' => 'Dhaka support hours v2',
        'windows' => [
            ['weekday' => 7, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            ['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 3, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 4, 'opens_at' => '09:00', 'closes_at' => '18:00'],
        ],
        'holidays' => [['holiday_date' => '2027-02-21', 'name' => 'Language Martyrs Day']],
    ])->assertRedirect();

    $draft->refresh();
    expect($draft->name)->toBe('Dhaka support hours v2')
        ->and($draft->windows()->where('weekday', 1)->value('closes_at'))->toStartWith('17:00')
        ->and($draft->holidays()->count())->toBe(1);

    $sourceCurrentPolicyId = TicketingSetting::query()->value('current_sla_policy_id');
    $this->post(route('dashboard.calendars.publish', $draft))->assertRedirect();
    expect($draft->fresh()->publication_state)->toBe(PublicationState::Published)
        ->and((int) TicketingSetting::query()->value('current_sla_policy_id'))->toBe((int) $sourceCurrentPolicyId);

    $this->put(route('dashboard.calendars.update', $draft), [
        'name' => 'Should not be editable',
        'windows' => [['weekday' => 7, 'opens_at' => '09:00', 'closes_at' => '18:00']],
    ])->assertSessionHasErrors('calendar');
    expect($draft->fresh()->name)->toBe('Dhaka support hours v2');
});

it('protects the current policy calendar and validates windows before publish', function (): void {
    $currentCalendar = CalendarVersion::query()->where('version_no', 1)->firstOrFail();
    $this->actingAs($this->admin)
        ->patch(route('dashboard.calendars.availability', $currentCalendar), ['is_active' => false])
        ->assertSessionHasErrors('calendar');
    expect($currentCalendar->fresh()->is_active)->toBeTrue();

    $this->post(route('dashboard.calendars.clone'), ['source_calendar_id' => $currentCalendar->id]);
    $draft = CalendarVersion::query()->where('version_no', 2)->firstOrFail();
    $this->put(route('dashboard.calendars.update', $draft), [
        'name' => 'Invalid schedule',
        'windows' => [
            ['weekday' => 7, 'opens_at' => '18:00', 'closes_at' => '09:00'],
        ],
        'holidays' => [],
    ])->assertSessionHasErrors('windows.0.closes_at');
    expect($draft->publication_state)->toBe(PublicationState::Draft);
});

it('rejects calendar management from an agent role', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($agent)->get(route('dashboard.calendars.index'))->assertRedirect();
});

it('keeps bulk calendar archival partial when the current calendar is selected', function (): void {
    $current = CalendarVersion::query()->where('version_no', 1)->firstOrFail();
    $this->actingAs($this->admin)->post(route('dashboard.calendars.clone'), ['source_calendar_id' => $current->id]);
    $draft = CalendarVersion::query()->where('version_no', 2)->firstOrFail();

    $this->post(route('dashboard.calendars.bulk'), [
        'operation_key' => (string) Str::uuid(),
        'action' => 'archive',
        'selected_ids' => [$current->id, $draft->id],
    ])->assertRedirect()->assertSessionHas('bulk_failures');

    expect($current->fresh()->is_active)->toBeTrue()
        ->and(CalendarVersion::withTrashed()->findOrFail($draft->id)->trashed())->toBeTrue();
});
