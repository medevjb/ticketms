<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TicketMessage> */
class TicketMessageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => User::factory(),
            'body' => fake()->paragraph(),
            'visibility' => 'public',
            'operation_key' => (string) Str::uuid(),
        ];
    }
}
