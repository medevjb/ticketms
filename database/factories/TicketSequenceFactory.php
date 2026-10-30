<?php

namespace Database\Factories;

use App\Models\TicketSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketSequence> */
class TicketSequenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'year' => fake()->unique()->numberBetween(2000, 9999),
            'last_number' => 0,
        ];
    }
}
