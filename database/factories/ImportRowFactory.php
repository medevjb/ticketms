<?php

namespace Database\Factories;

use App\Models\ImportRow;
use App\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportRow> */
class ImportRowFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'import_run_id' => ImportRun::factory(),
            'row_number' => 1,
            'external_id' => 'CRM-1',
            'result' => 'invalid',
            'error_codes' => ['customer_email' => ['unknown_customer']],
            'processed_at' => now(),
        ];
    }
}
