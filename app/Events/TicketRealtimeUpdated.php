<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketRealtimeUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly array $recipientIds,
        public readonly array $payload,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('ticket.'.$this->ticketId)];
        foreach ($this->recipientIds as $recipientId) {
            $channels[] = new PrivateChannel('user.'.$recipientId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ticket.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
