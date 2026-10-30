<?php

namespace App\Services\Tickets;

use App\Enums\DeliveryStatus;
use App\Enums\MessageVisibility;
use App\Enums\NotificationChannel;
use App\Jobs\DeliverTicketNotification;
use App\Models\ActivityEntry;
use App\Models\NotificationDelivery;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotificationDeliveryService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    public function dispatchForActivity(int $activityId): int
    {
        $deliveries = NotificationDelivery::query()
            ->where('activity_entry_id', $activityId)
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->get(['id', 'channel']);

        foreach ($deliveries as $delivery) {
            $this->dispatch($delivery);
        }

        return $deliveries->count();
    }

    public function dispatchPending(?int $limit = null): int
    {
        $now = CarbonImmutable::now('UTC');
        $limit = min(500, max(1, $limit ?? (int) config('ticketing.processing.notification_batch_size', 100)));
        $deliveries = NotificationDelivery::query()
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $pending) use ($now): void {
                    $pending->where('delivery_status', DeliveryStatus::Pending->value)->where('available_at', '<=', $now);
                })->orWhere(function (Builder $expired): void {
                    $expired->where('delivery_status', DeliveryStatus::Processing->value)->where('lease_until', '<=', CarbonImmutable::now('UTC'));
                });
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'channel']);

        foreach ($deliveries as $delivery) {
            $this->dispatch($delivery);
        }

        return $deliveries->count();
    }

    public function deliver(int $deliveryId): void
    {
        $claim = $this->claim($deliveryId);
        if ($claim === null) {
            return;
        }

        try {
            $payload = $this->validatedPayload($claim['payload']);
            $recipient = User::query()->active()->whereKey($claim['recipient_id'])->first();
            if ($recipient === null || ! $this->canReceive($recipient, $payload['ticket_id'], $payload['activity_id'], $payload['event'])) {
                $this->markSkipped($deliveryId);

                return;
            }

            if ($claim['channel'] === NotificationChannel::Database
                && $recipient->notifications()->whereKey($claim['notification_uuid'])->exists()) {
                $this->markSent($deliveryId);

                return;
            }

            $notification = new TicketActivityNotification($payload, $claim['notification_uuid']);
            Notification::sendNow($recipient, $notification, [$claim['channel']->value]);
            $this->markSent($deliveryId);
        } catch (Throwable $exception) {
            $this->rescheduleOrFail($deliveryId, $claim['attempts'], $exception);
        }
    }

    /** @return array{recipient_id: int, channel: NotificationChannel, notification_uuid: string, payload: array<string, mixed>, attempts: int}|null */
    private function claim(int $deliveryId): ?array
    {
        return DB::transaction(function () use ($deliveryId): ?array {
            $delivery = NotificationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || in_array($delivery->delivery_status, [DeliveryStatus::Sent, DeliveryStatus::Skipped, DeliveryStatus::Failed], true)) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');
            if ($delivery->delivery_status === DeliveryStatus::Pending && $delivery->available_at->greaterThan($now)) {
                return null;
            }
            if ($delivery->delivery_status === DeliveryStatus::Processing && $delivery->lease_until?->greaterThan($now)) {
                return null;
            }

            $attempts = (int) $delivery->attempts + 1;
            if ($attempts > (int) config('ticketing.processing.notification_max_attempts', 5)) {
                $delivery->forceFill([
                    'delivery_status' => DeliveryStatus::Failed,
                    'lease_until' => null,
                    'last_error_code' => 'attempt_limit',
                ])->save();

                return null;
            }

            $delivery->forceFill([
                'delivery_status' => DeliveryStatus::Processing,
                'attempts' => $attempts,
                'lease_until' => $now->addSeconds((int) config('ticketing.processing.notification_lease_seconds', 85)),
                'last_error_code' => null,
            ])->save();

            return [
                'recipient_id' => (int) $delivery->recipient_id,
                'channel' => $delivery->channel,
                'notification_uuid' => $delivery->notification_uuid,
                'payload' => $delivery->payload,
                'attempts' => $attempts,
            ];
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload
     * @return array{event: string, ticket_id: int, ticket_number: string, activity_id: int, summary: string}
     */
    private function validatedPayload(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1
            || ! is_string($payload['event'] ?? null)
            || ! is_numeric($payload['ticket_id'] ?? null)
            || ! is_string($payload['ticket_number'] ?? null)
            || ! is_numeric($payload['activity_id'] ?? null)
            || ! is_string($payload['summary'] ?? null)) {
            throw new \UnexpectedValueException('Invalid ticket notification payload.');
        }

        return [
            'event' => $payload['event'],
            'ticket_id' => (int) $payload['ticket_id'],
            'ticket_number' => mb_substr($payload['ticket_number'], 0, 40),
            'activity_id' => (int) $payload['activity_id'],
            'summary' => mb_substr($payload['summary'], 0, 240),
        ];
    }

    private function canReceive(User $recipient, int $ticketId, int $activityId, string $event): bool
    {
        if (! $this->permissions->allows($recipient, 'tickets.view')) {
            return false;
        }

        $ticket = Ticket::query()->whereKey($ticketId)->first();
        if ($ticket === null) {
            return false;
        }

        if ($event === 'ticket.message_posted') {
            $activity = ActivityEntry::query()->whereKey($activityId)->first();
            if ($activity === null || $activity->visibility !== MessageVisibility::Public) {
                return false;
            }
        }

        $roles = $recipient->tyroRoleSlugs();
        if (in_array('admin', $roles, true)) {
            return true;
        }
        if (in_array('customer', $roles, true)) {
            if ($recipient->email_verified_at === null || (int) $ticket->customer_id !== (int) $recipient->getKey()) {
                return false;
            }

            return true;
        }
        if (in_array('agent', $roles, true) && (int) $ticket->assigned_agent_id === (int) $recipient->getKey()) {
            return true;
        }

        return false;
    }

    private function markSent(int $deliveryId): void
    {
        NotificationDelivery::query()->whereKey($deliveryId)->where('delivery_status', DeliveryStatus::Processing)->update([
            'delivery_status' => DeliveryStatus::Sent->value,
            'sent_at' => CarbonImmutable::now('UTC'),
            'lease_until' => null,
            'last_error_code' => null,
        ]);
    }

    private function markSkipped(int $deliveryId): void
    {
        NotificationDelivery::query()->whereKey($deliveryId)->where('delivery_status', DeliveryStatus::Processing)->update([
            'delivery_status' => DeliveryStatus::Skipped->value,
            'lease_until' => null,
            'last_error_code' => 'recipient_scope_revoked',
        ]);
    }

    private function rescheduleOrFail(int $deliveryId, int $attempts, Throwable $exception): void
    {
        $maxAttempts = (int) config('ticketing.processing.notification_max_attempts', 5);
        $backoff = config('ticketing.processing.notification_backoff_seconds', [10, 60, 300, 900]);
        $terminal = $attempts >= $maxAttempts;
        $errorCode = mb_substr(class_basename($exception), 0, 80);
        NotificationDelivery::query()->whereKey($deliveryId)->where('delivery_status', DeliveryStatus::Processing)->update([
            'delivery_status' => $terminal ? DeliveryStatus::Failed->value : DeliveryStatus::Pending->value,
            'available_at' => $terminal ? CarbonImmutable::now('UTC') : CarbonImmutable::now('UTC')->addSeconds((int) ($backoff[min($attempts - 1, count($backoff) - 1)] ?? 900)),
            'lease_until' => null,
            'last_error_code' => $errorCode,
        ]);

        Log::warning('Ticket notification delivery failed.', [
            'delivery_id' => $deliveryId,
            'error_code' => $errorCode,
            'terminal' => $terminal,
        ]);
    }

    private function dispatch(NotificationDelivery $delivery): void
    {
        $queue = match ($delivery->channel) {
            NotificationChannel::Database => (string) config('ticketing.processing.notification_database_queue', 'notifications-database'),
            NotificationChannel::Mail => (string) config('ticketing.processing.notification_mail_queue', 'notifications-mail'),
        };

        DeliverTicketNotification::dispatch((int) $delivery->getKey())->onQueue($queue);
    }
}
