<?php

namespace App\Console\Commands;

use App\Services\Tickets\TicketEscalationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ticketing:scan-sla')]
#[Description('Queue a bounded, rotating scan of due ticket SLA thresholds.')]
class ScanTicketSla extends Command
{
    public function handle(TicketEscalationService $escalations): int
    {
        $count = $escalations->dispatchDueTickets();
        $this->info("Queued {$count} ticket SLA evaluations.");

        return self::SUCCESS;
    }
}
