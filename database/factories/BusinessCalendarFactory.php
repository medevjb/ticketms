<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessCalendar> */
class BusinessCalendarFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Support calendar',
            'timezone' => 'Asia/Dhaka',
        ];
    }
}
