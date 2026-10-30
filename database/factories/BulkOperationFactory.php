<?php

namespace Database\Factories;

use App\Models\BulkOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BulkOperation> */
class BulkOperationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operation_key' => (string) Str::uuid(),
            'actor_id' => User::factory(),
            'module' => 'tickets',
            'action' => 'resolve',
            'submitted_count' => 1,
            'started_at' => now(),
        ];
    }
}
