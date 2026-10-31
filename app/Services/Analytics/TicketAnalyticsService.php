<?php

namespace App\Services\Analytics;

use App\Enums\TicketStatus;
use App\Models\ActivityEntry;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketAnalyticsService
{
    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(private readonly EffectivePermissionService $permissions) {}

    /** @return array<string, mixed> */
    public function dashboard(User $actor, ?string $fromInput, ?string $toInput): array
    {
        if (! $this->permissions->allows($actor, 'analytics.view')) {
            throw new AuthorizationException;
        }

        $role = $this->analyticsRole($actor);
        [$fromDate, $toDate] = $this->dateRange($fromInput, $toInput);
        $key = implode(':', [
            'ticket-analytics',
            'v2',
            $role === 'admin' ? 'organization' : (string) $actor->getKey(),
            $role === 'admin' ? 'shared' : (string) $actor->authorization_version,
            hash('sha256', implode('|', $actor->tyroRoleSlugs())),
            $role,
            $fromDate->format('Y-m-d'),
            $toDate->format('Y-m-d'),
        ]);

        $dashboard = $role === 'admin'
            ? Cache::remember($key, now()->addSeconds(60), fn (): array => $this->buildDashboard($actor, $role, $fromDate, $toDate))
            : $this->buildDashboard($actor, $role, $fromDate, $toDate);

        $dashboard['recent_tickets'] = $this->ticketScope($actor, $role)
            ->latest('created_at')->latest('id')->limit(10)->get([
                'id', 'number', 'subject', 'status', 'is_active', 'created_at', 'sla_due_at', 'assigned_agent_id',
            ]);

        return $dashboard;
    }

    /** @return array<string, mixed> */
    private function buildDashboard(User $actor, string $role, CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        $now = CarbonImmutable::now('UTC');
        $fromUtc = $fromDate->startOfDay()->utc();
        $toExclusiveUtc = $toDate->addDay()->startOfDay()->utc();
        $periodDays = (int) $fromDate->diffInDays($toExclusiveUtc->setTimezone(self::TIMEZONE)->startOfDay());
        $previousFrom = $fromDate->subDays($periodDays)->startOfDay();
        $previousToExclusive = $fromDate->startOfDay();
        $activeStatuses = [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer];
        $currentTickets = $this->ticketScope($actor, $role);
        $backlog = (clone $currentTickets)->whereIn('status', $activeStatuses);
        $aggregate = (clone $currentTickets)->toBase()->selectRaw(
            'COUNT(CASE WHEN status IN (?, ?, ?) THEN id END) as backlog, '
            .'COUNT(CASE WHEN status IN (?, ?) AND sla_warning_at <= ? AND sla_due_at > ? THEN id END) as approaching, '
            .'COUNT(CASE WHEN status IN (?, ?) AND sla_due_at <= ? THEN id END) as breached, '
            .'COUNT(CASE WHEN first_breached_at IS NOT NULL THEN id END) as ever_breached, '
            .'COUNT(CASE WHEN status = ? THEN id END) as paused',
            [
                ...array_map(fn (TicketStatus $status): string => $status->value, $activeStatuses),
                TicketStatus::Open->value,
                TicketStatus::InProgress->value,
                $now,
                $now,
                TicketStatus::Open->value,
                TicketStatus::InProgress->value,
                $now,
                TicketStatus::WaitingForCustomer->value,
            ],
        )->first();
        $statusBreakdown = (clone $currentTickets)->toBase()
            ->whereIn('status', $activeStatuses)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->statusLabel((string) $row->status),
                'value' => (int) $row->total,
            ])
            ->values()
            ->all();

        $createdInPeriod = $this->createdCount($actor, $role, $fromUtc, $toExclusiveUtc);
        $createdInPreviousPeriod = $this->createdCount($actor, $role, $previousFrom->utc(), $previousToExclusive->utc());
        $resolvedInPeriod = $this->eventTicketCount($actor, $role, 'ticket.resolve', $fromUtc, $toExclusiveUtc);
        $resolvedInPreviousPeriod = $this->eventTicketCount($actor, $role, 'ticket.resolve', $previousFrom->utc(), $previousToExclusive->utc());
        $closedInPeriod = $this->eventTicketCount($actor, $role, 'ticket.close', $fromUtc, $toExclusiveUtc);
        $resolutionCycles = $this->resolutionSummary($actor, $role, $fromUtc, $toExclusiveUtc);
        $dailyCreated = $this->dailyTicketCounts($actor, $role, $fromUtc, $toExclusiveUtc);
        $dailyResolved = $this->dailyEventCounts($actor, $role, 'ticket.resolve', $fromUtc, $toExclusiveUtc);
        $dailyClosed = $this->dailyEventCounts($actor, $role, 'ticket.close', $fromUtc, $toExclusiveUtc);
        $daily = [];
        for ($day = $fromDate->startOfDay(); $day->lessThanOrEqualTo($toDate->startOfDay()); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $daily[] = [
                'date' => $date,
                'created' => (int) ($dailyCreated[$date] ?? 0),
                'resolved' => (int) ($dailyResolved[$date] ?? 0),
                'closed' => (int) ($dailyClosed[$date] ?? 0),
            ];
        }

        $metrics = [
            'backlog' => (int) $aggregate->backlog,
            'created' => $createdInPeriod,
            'created_change' => $this->percentChange($createdInPeriod, $createdInPreviousPeriod),
            'resolved' => $resolvedInPeriod,
            'resolved_change' => $this->percentChange($resolvedInPeriod, $resolvedInPreviousPeriod),
            'closed' => $closedInPeriod,
            'resolution_cycles' => $resolutionCycles['count'],
            'resolution_samples' => $resolutionCycles['sample_count'],
            'sla_met_cycles' => $resolutionCycles['met'],
            'sla_compliance_percent' => $resolutionCycles['compliance_percent'],
            'average_resolution_seconds' => $resolutionCycles['average_seconds'],
            'median_resolution_seconds' => $resolutionCycles['median_seconds'],
            'approaching' => (int) $aggregate->approaching,
            'breached' => (int) $aggregate->breached,
            'ever_breached' => (int) $aggregate->ever_breached,
            'paused' => (int) $aggregate->paused,
            'critical' => (clone $backlog)->whereHas('priority', fn (Builder $query): Builder => $query->where('slug', 'critical'))->count(),
        ];

        if ($role === 'admin') {
            $metrics['unassigned'] = (clone $backlog)->whereNull('assigned_agent_id')->count();
        } elseif ($role === 'agent') {
            $metrics['queue_preview'] = Ticket::query()
                ->whereNull('assigned_agent_id')
                ->whereIn('status', $activeStatuses)
                ->count();
        } elseif ($role === 'customer') {
            $metrics['public_staff_replies'] = TicketMessage::query()
                ->where('visibility', 'public')
                ->where('is_active', true)
                ->where('author_id', '!=', $actor->getKey())
                ->where('created_at', '>=', $fromUtc)
                ->where('created_at', '<', $toExclusiveUtc)
                ->whereHas('ticket', fn (Builder $query): Builder => $query->where('customer_id', $actor->getKey())->whereNull('tickets.deleted_at'))
                ->count();
        }

        return [
            'role' => $role,
            'filters' => ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')],
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'metrics' => $metrics,
            'status_breakdown' => $statusBreakdown,
            'priority_breakdown' => $role === 'admin' ? $this->currentBreakdown($backlog, 'priorities') : [],
            'category_breakdown' => $role === 'admin' ? $this->currentBreakdown($backlog, 'categories') : [],
            'agent_workload' => $role === 'admin' ? $this->agentWorkload($activeStatuses) : [],
            'daily' => $daily,
        ];
    }

    /** @return Builder<Ticket> */
    private function ticketScope(User $actor, string $role, bool $includeArchived = false): Builder
    {
        $query = Ticket::query();
        if ($role === 'admin' && $includeArchived) {
            $query->withTrashed();
        }
        if ($role === 'agent') {
            $query->where('assigned_agent_id', $actor->getKey());
        } elseif ($role === 'customer') {
            $query->where('customer_id', $actor->getKey());
        }

        return $query;
    }

    private function createdCount(User $actor, string $role, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): int
    {
        return $this->ticketScope($actor, $role, $role === 'admin')
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toExclusiveUtc)
            ->count();
    }

    private function eventTicketCount(User $actor, string $role, string $action, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): int
    {
        return $this->eventScope($actor, $role, $action, $fromUtc, $toExclusiveUtc)->distinct('ticket_id')->count('ticket_id');
    }

    /** @return array{count: int, sample_count: int, met: int, compliance_percent: float|null, average_seconds: float|null, median_seconds: float|null} */
    private function resolutionSummary(User $actor, string $role, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): array
    {
        $events = $this->eventScope($actor, $role, 'ticket.resolve', $fromUtc, $toExclusiveUtc);
        $eventCount = (clone $events)->count();
        $connectionDriver = DB::connection()->getDriverName();
        $consumedExpression = $connectionDriver === 'mysql'
            ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(activity_entries.changes, '$.sla_consumed_seconds')) AS UNSIGNED)"
            : "CAST(json_extract(activity_entries.changes, '$.sla_consumed_seconds') AS INTEGER)";
        $targetExpression = $connectionDriver === 'mysql'
            ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(activity_entries.changes, '$.sla_target_seconds')) AS UNSIGNED)"
            : "CAST(json_extract(activity_entries.changes, '$.sla_target_seconds') AS INTEGER)";
        $withSnapshots = (clone $events)->whereRaw("JSON_EXTRACT(activity_entries.changes, '$.sla_consumed_seconds') IS NOT NULL")
            ->whereRaw("JSON_EXTRACT(activity_entries.changes, '$.sla_target_seconds') IS NOT NULL");
        $sampleCount = (clone $withSnapshots)->count();
        if ($sampleCount === 0) {
            return ['count' => $eventCount, 'sample_count' => 0, 'met' => 0, 'compliance_percent' => null, 'average_seconds' => null, 'median_seconds' => null];
        }

        $met = (clone $withSnapshots)->whereRaw("{$consumedExpression} <= {$targetExpression}")->count();
        $average = (clone $withSnapshots)->avg(DB::raw($consumedExpression));
        $middle = intdiv($sampleCount - 1, 2);
        $medianValues = (clone $withSnapshots)
            ->selectRaw("{$consumedExpression} as active_seconds")
            ->orderBy('active_seconds')
            ->offset($middle)
            ->limit($sampleCount % 2 === 0 ? 2 : 1)
            ->pluck('active_seconds');
        $median = $medianValues->count() === 2
            ? (((float) $medianValues[0] + (float) $medianValues[1]) / 2)
            : (float) $medianValues->first();

        return [
            'count' => $eventCount,
            'sample_count' => $sampleCount,
            'met' => $met,
            'compliance_percent' => round(($met / $sampleCount) * 100, 1),
            'average_seconds' => round((float) $average, 1),
            'median_seconds' => round($median, 1),
        ];
    }

    /** @return Builder<ActivityEntry> */
    private function eventScope(User $actor, string $role, string $action, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): Builder
    {
        $query = ActivityEntry::query()
            ->where('action', $action)
            ->where('occurred_at', '>=', $fromUtc)
            ->where('occurred_at', '<', $toExclusiveUtc);

        if ($role === 'customer') {
            $query->whereHas('ticket', fn (Builder $tickets): Builder => $tickets->where('customer_id', $actor->getKey())->whereNull('tickets.deleted_at'));
        } elseif ($role === 'agent') {
            $agentIdExpression = DB::connection()->getDriverName() === 'mysql'
                ? "JSON_UNQUOTE(JSON_EXTRACT(activity_entries.changes, '$.assigned_agent_id'))"
                : "json_extract(activity_entries.changes, '$.assigned_agent_id')";
            $query->where(function (Builder $builder) use ($actor, $agentIdExpression): void {
                $builder->whereRaw("{$agentIdExpression} = ?", [(string) $actor->getKey()])
                    ->orWhereHas('ticket', fn (Builder $tickets): Builder => $tickets->where('assigned_agent_id', $actor->getKey())->whereNull('tickets.deleted_at'));
            });
        }

        return $query;
    }

    /**
     * @param  list<TicketStatus>  $activeStatuses
     * @return list<array{name: string, total: int}>
     */
    private function agentWorkload(array $activeStatuses): array
    {
        $rows = DB::table('tickets')
            ->join('users', 'users.id', '=', 'tickets.assigned_agent_id')
            ->whereNull('tickets.deleted_at')
            ->whereIn('tickets.status', $activeStatuses)
            ->selectRaw('users.id, users.name, count(tickets.id) as total')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->orderBy('users.name')
            ->limit(10)
            ->get();

        return array_values($rows->map(fn (object $row): array => ['name' => (string) $row->name, 'total' => (int) $row->total])->all());
    }

    /**
     * @param  Builder<Ticket>  $backlog
     * @return list<array{name: string, total: int}>
     */
    private function currentBreakdown(Builder $backlog, string $table): array
    {
        if (! in_array($table, ['priorities', 'categories'], true)) {
            return [];
        }

        $foreignKey = $table === 'priorities' ? 'priority_id' : 'category_id';
        $rows = (clone $backlog)->toBase()
            ->join($table, $table.'.id', '=', 'tickets.'.$foreignKey)
            ->selectRaw($table.'.name, count(tickets.id) as total')
            ->groupBy($table.'.id', $table.'.name')
            ->orderByDesc('total')
            ->orderBy($table.'.name')
            ->limit(10)
            ->get();

        return array_values($rows->map(fn (object $row): array => ['name' => (string) $row->name, 'total' => (int) $row->total])->all());
    }

    /** @return array<string, int> */
    private function dailyTicketCounts(User $actor, string $role, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): array
    {
        $expression = $this->dayExpression('tickets.created_at');
        $rows = $this->ticketScope($actor, $role, $role === 'admin')->toBase()
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toExclusiveUtc)
            ->selectRaw("{$expression} as day_bucket, count(*) as total")
            ->groupBy('day_bucket')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [(string) $row->day_bucket => (int) $row->total])->all();
    }

    /** @return array<string, int> */
    private function dailyEventCounts(User $actor, string $role, string $action, CarbonImmutable $fromUtc, CarbonImmutable $toExclusiveUtc): array
    {
        $expression = $this->dayExpression('activity_entries.occurred_at');
        $rows = $this->eventScope($actor, $role, $action, $fromUtc, $toExclusiveUtc)->toBase()
            ->selectRaw("{$expression} as day_bucket, count(*) as total")
            ->groupBy('day_bucket')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [(string) $row->day_bucket => (int) $row->total])->all();
    }

    /** @return literal-string */
    private function dayExpression(string $column): string
    {
        if (! in_array($column, ['tickets.created_at', 'activity_entries.occurred_at'], true)) {
            throw new \InvalidArgumentException('Unsupported analytics date column.');
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return match ($column) {
                'tickets.created_at' => "date(tickets.created_at, '+6 hours')",
                'activity_entries.occurred_at' => "date(activity_entries.occurred_at, '+6 hours')",
            };
        }

        return match ($column) {
            'tickets.created_at' => 'DATE(DATE_ADD(tickets.created_at, INTERVAL 6 HOUR))',
            'activity_entries.occurred_at' => 'DATE(DATE_ADD(activity_entries.occurred_at, INTERVAL 6 HOUR))',
        };
    }

    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function analyticsRole(User $actor): string
    {
        $roles = $actor->tyroRoleSlugs();
        foreach (['admin', 'agent', 'customer'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        throw new AuthorizationException;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function dateRange(?string $fromInput, ?string $toInput): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        if ($fromInput === null && $toInput === null) {
            $to = $today;
            $from = $today->subDays(29);
        } elseif ($fromInput !== null && $toInput !== null) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromInput, self::TIMEZONE);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $toInput, self::TIMEZONE);
        } elseif ($fromInput !== null) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromInput, self::TIMEZONE);
            $to = $from->greaterThan($today) ? $from : $today;
        } else {
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $toInput, self::TIMEZONE);
            $from = $to->subDays(29);
        }

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'Choose a valid date range.']);
        }
        if ($from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose a date range of 366 days or less.']);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            TicketStatus::Open->value => 'Open',
            TicketStatus::InProgress->value => 'In progress',
            TicketStatus::WaitingForCustomer->value => 'Waiting for customer',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
