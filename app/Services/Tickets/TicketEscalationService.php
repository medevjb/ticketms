<?php

namespace App\Services\Tickets;

use App\Enums\ActorKind;
use App\Enums\EscalationStage;
use App\Enums\MessageVisibility;
use App\Enums\TicketStatus;
use App\Jobs\EvaluateTicketSla;
use App\Models\Ticket;
use App\Models\TicketEscalation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class TicketEscalationService
{
    public function __construct(
        private readonly SlaService $sla,
        private readonly TicketActivityService $activity,
    ) {}

    public function dispatchDueTickets(?int $limit = null): int
    {
        $limit = min(500, max(1, $limit ?? (int) config('ticketing.processing.sla_batch_size', 200)));
        $now = CarbonImmutable::now('UTC');
        $cursorKey = 'ticketing.sla.scan_cursor';
        $cursor = (int) Cache::get($cursorKey, 0);
        $ids = $this->dueTicketQuery($now)->where('id', '>', $cursor)->orderBy('id')->limit($limit)->pluck('id');

        if ($ids->count() < $limit && $cursor > 0) {
            $wrapped = $this->dueTicketQuery($now)->where('id', '<=', $cursor)
                ->orderBy('id')->limit($limit - $ids->count())->pluck('id');
            $ids = $ids->concat($wrapped)->unique()->values();
        }

        if ($ids->isEmpty()) {
            return 0;
        }

        foreach ($ids as $id) {
            EvaluateTicketSla::dispatch((int) $id)
                ->onQueue((string) config('ticketing.processing.sla_queue', 'sla'));
        }

        Cache::put($cursorKey, (int) $ids->last(), now()->addDay());

        return $ids->count();
    }

    public function evaluate(int $ticketId): void
    {
        DB::transaction(function () use ($ticketId): void {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
            if ($ticket !== null) {
                $this->recordCurrentStageInTransaction($ticket, CarbonImmutable::now('UTC')->startOfSecond());
            }
        }, attempts: 3);
    }

    public function recordCurrentStageInTransaction(Ticket $ticket, CarbonImmutable $asOf): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SLA escalation writes must share the ticket transaction.');
        }
        if (! in_array($ticket->status, [TicketStatus::Open, TicketStatus::InProgress], true)) {
            return false;
        }

        $snapshot = $this->sla->snapshot($ticket, $asOf);
        $stage = match ($snapshot['state']) {
            'breached' => EscalationStage::Breached,
            'approaching' => EscalationStage::Approaching,
            default => null,
        };
        if ($stage === null) {
            return false;
        }

        $exists = TicketEscalation::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('resolution_cycle', $ticket->resolution_cycle)
            ->where('stage', $stage->value)
            ->exists();
        if ($exists) {
            return false;
        }

        $thresholdAt = $stage === EscalationStage::Breached
            ? $this->sla->breachAt($ticket, $asOf)
            : $snapshot['warning_at'];
        if ($thresholdAt === null) {
            return false;
        }

        $action = 'ticket.sla_'.$stage->value;
        $operationKey = (string) Str::uuid();
        $activity = $this->activity->record(
            $ticket,
            null,
            ActorKind::System,
            $action,
            $operationKey,
            [
                'resolution_cycle' => $ticket->resolution_cycle,
                'stage' => $stage->value,
                'threshold_at' => $thresholdAt->toIso8601String(),
                'detected_at' => $asOf->toIso8601String(),
                'consumed_seconds' => $snapshot['consumed_seconds'],
                'target_seconds' => $ticket->sla_target_seconds,
                'sla_policy_id' => $ticket->sla_policy_id,
                'calendar_version_id' => $ticket->calendar_version_id,
            ],
            MessageVisibility::Internal,
            Ticket::class,
            (int) $ticket->getKey(),
        );

        TicketEscalation::query()->forceCreate([
            'ticket_id' => $ticket->getKey(),
            'resolution_cycle' => $ticket->resolution_cycle,
            'stage' => $stage,
            'threshold_at' => $thresholdAt,
            'detected_at' => $asOf,
            'activity_entry_id' => $activity->getKey(),
        ]);

        if ($stage === EscalationStage::Breached && $ticket->first_breached_at === null) {
            $ticket->forceFill(['first_breached_at' => $thresholdAt])->save();
        }

        $recipients = [
            ...($ticket->assigned_agent_id === null ? [] : [(int) $ticket->assigned_agent_id]),
            ...$this->activity->activeAdministratorIds(),
        ];
        $summary = $stage === EscalationStage::Breached
            ? "Ticket {$ticket->number} has breached its resolution SLA."
            : "Ticket {$ticket->number} is approaching its resolution SLA deadline.";
        $this->activity->queueDeliveryIntents($activity, $ticket, $action, $recipients, $summary);

        return true;
    }

    /** @return Builder<Ticket> */
    private function dueTicketQuery(CarbonImmutable $now): Builder
    {
        return Ticket::query()
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $approaching) use ($now): void {
                    $approaching->where('sla_warning_at', '<=', $now)
                        ->where('sla_due_at', '>', $now)
                        ->whereNotExists(function (QueryBuilder $escalations): void {
                            $escalations->selectRaw('1')
                                ->from('ticket_escalations')
                                ->whereColumn('ticket_escalations.ticket_id', 'tickets.id')
                                ->whereColumn('ticket_escalations.resolution_cycle', 'tickets.resolution_cycle')
                                ->where('ticket_escalations.stage', EscalationStage::Approaching->value);
                        });
                })->orWhere(function (Builder $breached) use ($now): void {
                    $breached->where('sla_due_at', '<=', $now)
                        ->whereNotExists(function (QueryBuilder $escalations): void {
                            $escalations->selectRaw('1')
                                ->from('ticket_escalations')
                                ->whereColumn('ticket_escalations.ticket_id', 'tickets.id')
                                ->whereColumn('ticket_escalations.resolution_cycle', 'tickets.resolution_cycle')
                                ->where('ticket_escalations.stage', EscalationStage::Breached->value);
                        });
                });
            });
    }
}
