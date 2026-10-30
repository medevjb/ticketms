<?php

namespace Database\Factories;

use App\Models\CommandReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommandReceipt> */
class CommandReceiptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operation_key' => (string) Str::uuid(),
            'actor_id' => User::factory(),
            'action' => 'ticket.created',
            'payload_hash' => hash('sha256', 'payload'),
            'result_code' => 'created',
            'completed_at' => now(),
        ];
    }
}
