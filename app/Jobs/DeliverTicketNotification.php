<?php

namespace App\Jobs;

use App\Services\Tickets\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverTicketNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 60];

    public int $timeout = 60;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(NotificationDeliveryService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
