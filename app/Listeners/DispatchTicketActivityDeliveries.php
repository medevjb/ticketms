<?php

namespace App\Listeners;

use App\Events\TicketActivityRecorded;
use App\Services\Tickets\NotificationDeliveryService;

class DispatchTicketActivityDeliveries
{
    public function __construct(private readonly NotificationDeliveryService $deliveries) {}

    public function handle(TicketActivityRecorded $event): void
    {
        $this->deliveries->dispatchForActivity($event->activityId);
    }
}
