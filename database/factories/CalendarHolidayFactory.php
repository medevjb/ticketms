<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarHoliday> */
class CalendarHolidayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_calendar_id' => BusinessCalendar::factory(),
            'holiday_date' => '2026-12-16',
            'name' => 'Holiday',
        ];
    }
}
