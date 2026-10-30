<?php

namespace Database\Factories;

use App\Models\BulkOperation;
use App\Models\BulkOperationItem;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BulkOperationItem> */
class BulkOperationItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bulk_operation_id' => BulkOperation::factory(),
            'subject_type' => 'ticket',
            'subject_id' => fn (): int => Ticket::factory()->create()->getKey(),
            'result' => 'pending',
        ];
    }
}
