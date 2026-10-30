<?php

namespace App\Jobs;

use App\Services\Tickets\TicketEscalationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateTicketSla implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 60];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(TicketEscalationService $escalations): void
    {
        $escalations->evaluate($this->ticketId);
    }
}
