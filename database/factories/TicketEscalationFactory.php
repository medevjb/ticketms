<?php

namespace Database\Factories;

use App\Models\ActivityEntry;
use App\Models\Ticket;
use App\Models\TicketEscalation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketEscalation> */
class TicketEscalationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'resolution_cycle' => 1,
            'stage' => 'approaching',
            'threshold_at' => now(),
            'detected_at' => now(),
            'activity_entry_id' => fn (array $attributes): int => ActivityEntry::factory()->create(['ticket_id' => $attributes['ticket_id'], 'subject_type' => 'ticket', 'subject_id' => $attributes['ticket_id']])->getKey(),
        ];
    }
}
