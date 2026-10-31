<?php

namespace App\Services\Tickets;

use App\Enums\MessageVisibility;
use App\Events\TicketRealtimeUpdated;
use App\Models\ActivityEntry;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketRealtimeService
{
    public function publishActivity(int $activityId): void
    {
        $activity = ActivityEntry::query()->with(['ticket.priority', 'ticket.assignedAgent'])->find($activityId);
        $ticket = $activity?->ticket;

        if ($activity === null || $ticket === null || $ticket->trashed()) {
            return;
        }

        $message = null;
        if ($activity->action === 'ticket.message_posted') {
            if ($activity->visibility !== MessageVisibility::Public) {
                return;
            }

            $message = TicketMessage::query()
                ->where('ticket_id', $ticket->getKey())
                ->whereKey($activity->subject_id)
                ->where('visibility', MessageVisibility::Public)
                ->where('is_active', true)
                ->with([
                    'author',
                    'attachments' => fn (HasMany $attachments) => $attachments->where('is_active', true),
                ])
                ->first();

            if ($message === null) {
                return;
            }
        }

        $recipientIds = [
            (int) $ticket->customer_id,
            (int) ($ticket->assigned_agent_id ?? 0),
            (int) data_get($activity->changes, 'assigned_agent_id.before', 0),
            (int) data_get($activity->changes, 'assigned_agent_id.after', 0),
        ];
        $recipients = User::query()->active()->whereIn('id', array_filter(array_unique($recipientIds)))->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        TicketRealtimeUpdated::dispatch(
            (int) $ticket->getKey(),
            $recipients,
            [
                'action' => $activity->action,
                'ticket' => [
                    'id' => (int) $ticket->getKey(),
                    'number' => $ticket->number,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status->value,
                    'priority' => $ticket->priority?->name,
                    'assigned_agent' => $ticket->assignedAgent?->name,
                    'lock_version' => $ticket->lock_version,
                    'updated_at' => $ticket->updated_at?->toIso8601String(),
                    'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
                ],
                'message' => $message === null ? null : [
                    'id' => (int) $message->getKey(),
                    'body' => $message->body,
                    'author_id' => (int) $message->author_id,
                    'author' => $message->author?->name ?? 'Support',
                    'created_at' => $message->created_at?->toIso8601String(),
                    'attachments' => $message->attachments->map(static fn ($attachment): array => [
                        'id' => (int) $attachment->getKey(),
                        'name' => $attachment->original_name,
                        'size_bytes' => (int) $attachment->size_bytes,
                        'is_image' => $attachment->isPreviewableImage(),
                    ])->values()->all(),
                ],
            ],
        );
    }
}
