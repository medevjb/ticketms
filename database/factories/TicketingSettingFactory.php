<?php

namespace Database\Factories;

use App\Models\TicketingSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketingSetting> */
class TicketingSettingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => 1,
            'current_sla_policy_id' => null,
        ];
    }
}
