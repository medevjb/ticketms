<?php

namespace Database\Factories;

use App\Models\CalendarVersion;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicy> */
class SlaPolicyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'version_no' => fake()->unique()->numberBetween(1, 1000000),
            'name' => 'Resolution policy',
            'calendar_version_id' => CalendarVersion::factory()->published(),
            'warning_percent' => 80,
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
