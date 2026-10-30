<?php

namespace Database\Factories;

use App\Models\Priority;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Priority> */
class PriorityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'slug' => fake()->unique()->slug(1),
            'sort_order' => 10,
        ];
    }
}
