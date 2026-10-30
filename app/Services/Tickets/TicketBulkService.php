<?php

namespace App\Services\Tickets;

use App\Enums\TicketBulkAction;
use App\Models\User;
use App\Services\BulkOperations\BulkOperationService;

class TicketBulkService
{
    public function __construct(
        private readonly BulkOperationService $operations,
        private readonly TicketService $tickets,
    ) {}

    /**
     * @param  list<int>  $ticketIds
     * @param  array<int, int>  $expectedVersions
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function execute(User $actor, TicketBulkAction $action, string $operationKey, array $ticketIds, array $expectedVersions): array
    {
        return $this->operations->execute(
            $actor,
            'tickets.bulk',
            $ticketIds,
            function (int $ticketId) use ($actor, $action, $operationKey, $expectedVersions): void {
                $version = $expectedVersions[$ticketId] ?? throw new \InvalidArgumentException('A selected ticket version is required.');
                $itemOperationKey = $this->itemOperationKey($operationKey, $ticketId);

                match ($action) {
                    TicketBulkAction::Enable => $this->tickets->setAvailability($actor, $ticketId, true, $version, $itemOperationKey),
                    TicketBulkAction::Disable => $this->tickets->setAvailability($actor, $ticketId, false, $version, $itemOperationKey),
                    TicketBulkAction::Archive => $this->tickets->archive($actor, $ticketId, $version, $itemOperationKey),
                    TicketBulkAction::Restore => $this->tickets->restore($actor, $ticketId, $version, $itemOperationKey),
                };
            },
        );
    }

    private function itemOperationKey(string $operationKey, int $ticketId): string
    {
        $hex = substr(hash('sha256', 'bulk-ticket:'.$operationKey.':'.$ticketId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
