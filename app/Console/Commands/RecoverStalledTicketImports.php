<?php

namespace App\Console\Commands;

use App\Services\Imports\TicketImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ticketing:recover-imports')]
#[Description('Redispatch stale queued or running ticket import checkpoints.')]
class RecoverStalledTicketImports extends Command
{
    public function handle(TicketImportService $imports): int
    {
        $count = $imports->dispatchStalled();
        $this->info("Redispatched {$count} stalled ticket imports.");

        return self::SUCCESS;
    }
}
