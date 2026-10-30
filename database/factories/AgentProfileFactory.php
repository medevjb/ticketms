<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentProfile> */
class AgentProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'auto_assignment_enabled' => false,
        ];
    }
}
