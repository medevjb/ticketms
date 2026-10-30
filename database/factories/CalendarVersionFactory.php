<?php

namespace Database\Factories;

use App\Models\CalendarVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarVersion> */
class CalendarVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'version_no' => fake()->unique()->numberBetween(1, 1000000),
            'name' => 'Support calendar',
            'timezone' => 'Asia/Dhaka',
            'publication_state' => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'publication_state' => 'published',
            'published_at' => now(),
        ]);
    }
}
