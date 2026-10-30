<?php

namespace Database\Factories;

use App\Models\CalendarVersion;
use App\Models\CalendarWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarWindow> */
class CalendarWindowFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'calendar_version_id' => CalendarVersion::factory(),
            'weekday' => 7,
            'opens_at' => '09:00:00',
            'closes_at' => '18:00:00',
        ];
    }
}
