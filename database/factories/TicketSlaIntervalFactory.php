<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketSlaInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketSlaInterval> */
class TicketSlaIntervalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'kind' => 'waiting',
            'started_at' => now(),
            'resolution_cycle' => 1,
        ];
    }
}
