<?php

use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationPermissionSeeder;
use Database\Seeders\TicketingConfigurationSeeder;

beforeEach(function (): void {
    $this->seed([TicketingConfigurationSeeder::class, AuthorizationPermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
});

it('lets administrators update the single calendar and add holidays directly', function (): void {
    $calendar = BusinessCalendar::query()->with('windows')->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('dashboard.calendars.index'))
        ->assertOk()
        ->assertSee('Business calendar')
        ->assertSee('Default support calendar')
        ->assertSee('Monthly schedule and holidays')
        ->assertSee('Previous month')
        ->assertSee('data-calendar-grid', false)
        ->assertSee('data-calendar-holiday-list', false);

    $this->put(route('dashboard.calendars.update'), [
        'name' => 'Dhaka support hours',
        'timezone' => 'Asia/Dhaka',
        'windows' => [
            ['weekday' => 7, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            ['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 3, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 4, 'opens_at' => '09:00', 'closes_at' => '18:00'],
        ],
        'holidays' => [['holiday_date' => '2027-02-21', 'name' => 'Language Martyrs Day']],
    ])->assertRedirect(route('dashboard.calendars.index'));

    expect(BusinessCalendar::query()->count())->toBe(1)
        ->and($calendar->fresh()->name)->toBe('Dhaka support hours')
        ->and($calendar->windows()->where('weekday', 1)->value('closes_at'))->toStartWith('17:00')
        ->and($calendar->holidays()->whereDate('holiday_date', '2027-02-21')->value('name'))->toBe('Language Martyrs Day');
});

it('rejects invalid calendar hours without changing the saved schedule', function (): void {
    $calendar = BusinessCalendar::query()->firstOrFail();

    $this->actingAs($this->admin)->put(route('dashboard.calendars.update'), [
        'name' => 'Invalid schedule',
        'timezone' => 'Asia/Dhaka',
        'windows' => [['weekday' => 7, 'opens_at' => '18:00', 'closes_at' => '09:00']],
        'holidays' => [],
    ])->assertSessionHasErrors('windows.0.closes_at');

    expect($calendar->fresh()->name)->toBe('Default support calendar')
        ->and($calendar->windows()->count())->toBe(5);
});

it('rejects duplicate holidays without changing the saved calendar', function (): void {
    $calendar = BusinessCalendar::query()->firstOrFail();

    $this->actingAs($this->admin)->put(route('dashboard.calendars.update'), [
        'name' => 'Duplicate holiday calendar',
        'timezone' => 'Asia/Dhaka',
        'windows' => [['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '18:00']],
        'holidays' => [
            ['holiday_date' => '2027-02-21', 'name' => 'First entry'],
            ['holiday_date' => '2027-02-21', 'name' => 'Duplicate entry'],
        ],
    ])->assertSessionHasErrors('holidays.1.holiday_date');

    expect($calendar->fresh()->name)->toBe('Default support calendar')
        ->and($calendar->holidays()->count())->toBe(0);
});

it('removes an existing holiday when the calendar is saved without it', function (): void {
    $calendar = BusinessCalendar::query()->firstOrFail();
    $holiday = CalendarHoliday::factory()->for($calendar)->create([
        'holiday_date' => '2027-03-26',
        'name' => 'Independence Day',
    ]);

    $this->actingAs($this->admin)->put(route('dashboard.calendars.update'), [
        'name' => $calendar->name,
        'timezone' => $calendar->timezone,
        'windows' => [
            ['weekday' => 7, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 3, 'opens_at' => '09:00', 'closes_at' => '18:00'],
            ['weekday' => 4, 'opens_at' => '09:00', 'closes_at' => '18:00'],
        ],
        'holidays' => [],
    ])->assertRedirect(route('dashboard.calendars.index'));

    $this->assertModelMissing($holiday);
});

it('rejects calendar management from an agent role', function (): void {
    $agent = User::factory()->create();
    $agent->assignRole(Role::query()->where('name', 'agent')->firstOrFail());

    $this->actingAs($agent)->get(route('dashboard.calendars.index'))->assertRedirect();
});
