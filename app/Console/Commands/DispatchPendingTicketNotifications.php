<?php

namespace App\Console\Commands;

use App\Services\Tickets\NotificationDeliveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ticketing:dispatch-notifications')]
#[Description('Dispatch pending ticket notification deliveries to their channel queues.')]
class DispatchPendingTicketNotifications extends Command
{
    public function handle(NotificationDeliveryService $deliveries): int
    {
        $count = $deliveries->dispatchPending();
        $this->info("Dispatched {$count} ticket notification deliveries.");

        return self::SUCCESS;
    }
}
