<?php

namespace Database\Factories;

use App\Models\ActivityEntry;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ActivityEntry> */
class ActivityEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'actor_id' => User::factory(),
            'actor_kind' => 'user',
            'action' => 'ticket.created',
            'visibility' => 'internal',
            'subject_type' => 'ticket',
            'subject_id' => fn (array $attributes): int => $attributes['ticket_id'],
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ];
    }
}
