<?php

namespace App\Listeners;

use App\Events\TicketActivityRecorded;
use App\Services\Tickets\TicketRealtimeService;

class BroadcastTicketActivityRealtimeUpdate
{
    public function __construct(private readonly TicketRealtimeService $realtime) {}

    public function handle(TicketActivityRecorded $event): void
    {
        $this->realtime->publishActivity($event->activityId);
    }
}
