<?php

namespace App\Services\Tickets;

use App\Enums\ActorKind;
use App\Enums\MessageVisibility;
use App\Events\TicketActivityRecorded;
use App\Models\ActivityEntry;
use App\Models\AgentProfile;
use App\Models\NotificationDelivery;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TicketActivityService
{
    /** @param array<string, mixed> $changes */
    public function record(
        Ticket $ticket,
        ?User $actor,
        ActorKind $actorKind,
        string $action,
        string $operationKey,
        array $changes,
        MessageVisibility $visibility = MessageVisibility::Internal,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): ActivityEntry {
        if (($actorKind === ActorKind::System) !== ($actor === null)) {
            throw new InvalidArgumentException('System activity must have no user actor; user activity must have a user actor.');
        }

        $activity = ActivityEntry::query()->forceCreate([
            'ticket_id' => $ticket->getKey(),
            'actor_id' => $actor?->getKey(),
            'actor_kind' => $actorKind,
            'action' => $action,
            'visibility' => $visibility,
            'subject_type' => $subjectType ?? Ticket::class,
            'subject_id' => $subjectId ?? (int) $ticket->getKey(),
            'operation_key' => $operationKey,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'changes' => $changes,
            'metadata' => null,
        ]);

        TicketActivityRecorded::dispatch((int) $activity->getKey());

        return $activity;
    }

    /** @param list<int|string> $recipientIds */
    public function queueDeliveryIntents(
        ActivityEntry $activity,
        Ticket $ticket,
        string $event,
        array $recipientIds,
        string $summary,
    ): void {
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
        if ($recipientIds === []) {
            return;
        }

        foreach (User::query()->active()->whereIn('id', $recipientIds)->get(['id']) as $recipient) {
            foreach (['database', 'mail'] as $channel) {
                NotificationDelivery::query()->forceCreate([
                    'activity_entry_id' => $activity->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'channel' => $channel,
                    'delivery_status' => 'pending',
                    'attempts' => 0,
                    'available_at' => CarbonImmutable::now('UTC'),
                    'lease_until' => null,
                    'notification_uuid' => (string) Str::uuid(),
                    'payload' => [
                        'schema_version' => 1,
                        'event' => $event,
                        'ticket_id' => $ticket->getKey(),
                        'ticket_number' => $ticket->number,
                        'activity_id' => $activity->getKey(),
                        'summary' => $summary,
                    ],
                    'last_error_code' => null,
                    'sent_at' => null,
                ]);
            }
        }
    }

    /** @return list<int> */
    public function activeAdministratorIds(): array
    {
        return array_values(User::query()->active()->whereHas('roles', function (Builder $query): void {
            $query->where('name', 'admin')->where('guard_name', 'web')->where('is_active', true)->whereNull('deleted_at');
        })->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /** @return list<int> */
    public function participantIds(Ticket $ticket): array
    {
        $ids = [(int) $ticket->customer_id];
        if ($ticket->assigned_agent_id !== null) {
            $ids[] = (int) $ticket->assigned_agent_id;
        }

        return array_values(array_unique($ids));
    }

    public function recordAgentEligibility(User $actor, AgentProfile $profile, bool $wasEnabled, bool $isEnabled, string $operationKey): ActivityEntry
    {
        return ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => 'agent.auto_assignment_toggled',
            'visibility' => MessageVisibility::Internal,
            'subject_type' => AgentProfile::class,
            'subject_id' => $profile->getKey(),
            'operation_key' => $operationKey,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'changes' => ['auto_assignment_enabled' => ['before' => $wasEnabled, 'after' => $isEnabled]],
            'metadata' => null,
        ]);
    }
}
