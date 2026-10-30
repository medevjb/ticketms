<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\Ticket;
use App\Models\TicketExternalReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TicketExternalReference> */
class TicketExternalReferenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'import_run_id' => ImportRun::factory(),
            'source' => fn (array $attributes): string => ImportRun::query()->whereKey($attributes['import_run_id'])->firstOrFail()->source,
            'external_id' => (string) Str::uuid(),
            'ticket_id' => Ticket::factory(),
        ];
    }
}
