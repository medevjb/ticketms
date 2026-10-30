<?php

namespace App\Services\Tickets;

use App\Models\CommandReceipt;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketOperationService
{
    /** @param array<string, mixed> $payload */
    public function reserve(User $actor, string $action, string $operationKey, array $payload): ?Ticket
    {
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $inserted = DB::table('command_receipts')->insertOrIgnore([
            'operation_key' => $operationKey,
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'payload_hash' => $payloadHash,
            'subject_type' => null,
            'subject_id' => null,
            'result_code' => 'pending',
            'created_at' => CarbonImmutable::now('UTC'),
            'completed_at' => null,
        ]) === 1;
        $receipt = CommandReceipt::query()->where('operation_key', $operationKey)->lockForUpdate()->firstOrFail();

        if ((int) $receipt->actor_id !== (int) $actor->getKey()
            || $receipt->action !== $action
            || ! hash_equals($receipt->payload_hash, $payloadHash)) {
            throw new HttpException(409, 'This operation key has already been used for a different request.');
        }
        if ($inserted) {
            return null;
        }
        if ($receipt->completed_at === null || $receipt->subject_type !== Ticket::class || $receipt->subject_id === null) {
            throw new HttpException(409, 'This operation is still being processed. Refresh and retry with a new request.');
        }

        return Ticket::withTrashed()->findOrFail($receipt->subject_id);
    }

    public function complete(string $operationKey, Ticket $ticket, string $resultCode): void
    {
        DB::table('command_receipts')->where('operation_key', $operationKey)->update([
            'subject_type' => Ticket::class,
            'subject_id' => $ticket->getKey(),
            'result_code' => $resultCode,
            'completed_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
