<?php

namespace Database\Factories;

use App\Models\CalendarHoliday;
use App\Models\CalendarVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarHoliday> */
class CalendarHolidayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'calendar_version_id' => CalendarVersion::factory(),
            'holiday_date' => '2026-12-16',
            'name' => 'Holiday',
        ];
    }
}
