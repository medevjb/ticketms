<?php

namespace Database\Factories;

use App\Models\Priority;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicyTarget> */
class SlaPolicyTargetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sla_policy_id' => SlaPolicy::factory(),
            'priority_id' => Priority::factory(),
            'target_seconds' => 7200,
        ];
    }
}
