<?php

namespace App\Jobs;

use App\Services\Imports\TicketImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessTicketImportChunk implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 110;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $importRunId, public readonly int $expectedLastRow) {}

    public function uniqueId(): string
    {
        return $this->importRunId.':'.$this->expectedLastRow;
    }

    public function handle(TicketImportService $imports): void
    {
        $imports->processChunk($this->importRunId, $this->expectedLastRow);
    }

    public function failed(?Throwable $exception): void
    {
        app(TicketImportService::class)->failRun($this->importRunId, 'processing_failed');
    }
}
