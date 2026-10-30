<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ImportRun> */
class ImportRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source' => 'CRM',
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'imports/'.Str::uuid().'.csv',
            'checksum' => hash('sha256', 'csv'),
            'processing_status' => 'queued',
        ];
    }
}
