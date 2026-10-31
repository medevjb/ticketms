<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TicketQueryService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->filteredQuery($actor, $filters);

        $sort = in_array($filters['sort'] ?? '', ['created_at', 'updated_at', 'number', 'sla_due_at'], true)
            ? (string) $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = in_array((int) ($filters['per_page'] ?? 15), [15, 30, 50], true)
            ? (int) ($filters['per_page'] ?? 15)
            : 15;

        return $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function filteredQuery(User $actor, array $filters): Builder
    {
        $this->authorize($actor, 'tickets.view');
        $this->authorize($actor, 'tickets.search');
        $this->ensureVerifiedCustomer($actor);

        $isAdministrator = $this->isAdministrator($actor);
        $query = Ticket::query()->with(['customer', 'assignedAgent', 'category', 'priority']);
        $status = (string) ($filters['status'] ?? 'active');
        if ($status === 'archived' && $isAdministrator) {
            $query->withTrashed()->onlyTrashed();
        } elseif ($status === 'resolved') {
            $query->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed]);
        } elseif ($status === 'closed') {
            $query->where('status', TicketStatus::Closed);
        } elseif ($status !== 'all') {
            $query->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer]);
        }

        if (! $isAdministrator) {
            if ($this->isCustomer($actor)) {
                $query->where('customer_id', $actor->getKey());
            } elseif ($this->isAgent($actor)) {
                $query->where('assigned_agent_id', $actor->getKey());
            } else {
                throw new AuthorizationException;
            }
        }

        if (isset($filters['priority_id'])) {
            $query->where('priority_id', (int) $filters['priority_id']);
        }
        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if ($isAdministrator && isset($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if ($isAdministrator && isset($filters['agent_id'])) {
            $query->where('assigned_agent_id', (int) $filters['agent_id']);
        }

        $this->applySlaFilter($query, (string) ($filters['sla_state'] ?? ''), CarbonImmutable::now('UTC'));
        $this->applyDateFilters($query, $filters, $this->isCustomer($actor) ? 'Asia/Dhaka' : (string) config('ticketing.display_timezone', 'Asia/Dhaka'));
        $this->applySearch($query, trim((string) ($filters['search'] ?? '')));

        if (($filters['assignment'] ?? '') === 'unassigned') {
            $query->whereNull('assigned_agent_id');
        } elseif (($filters['assignment'] ?? '') === 'assigned') {
            $query->whereNotNull('assigned_agent_id');
        }

        return $query;
    }

    /** @return Collection<int, Ticket> */
    public function unassignedQueuePreview(User $actor): Collection
    {
        $this->authorize($actor, 'tickets.view');
        if (! $this->isAgent($actor)) {
            return new Collection;
        }

        return Ticket::query()
            ->select(['id', 'number', 'subject', 'category_id', 'priority_id', 'status', 'created_at', 'sla_warning_at', 'sla_due_at', 'lock_version'])
            ->with(['category:id,name', 'priority:id,name,slug'])
            ->whereNull('assigned_agent_id')
            ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer])
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->limit(15)
            ->get();
    }

    /** @param Builder<Ticket> $query */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('number', 'like', "%{$search}%")
                    ->orWhereFullText(['subject', 'description'], $search);
            });

            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder->where('number', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    /** @param Builder<Ticket> $query */
    private function applySlaFilter(Builder $query, string $state, CarbonImmutable $now): void
    {
        match ($state) {
            'within' => $query->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])->where('sla_warning_at', '>', $now),
            'approaching' => $query->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])->where('sla_warning_at', '<=', $now)->where('sla_due_at', '>', $now),
            'breached' => $query->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])->where('sla_due_at', '<=', $now),
            'paused' => $query->where('status', TicketStatus::WaitingForCustomer),
            'stopped' => $query->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed]),
            default => null,
        };
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters, string $timezone): void
    {
        if (! empty($filters['date_from'])) {
            $from = CarbonImmutable::parse((string) $filters['date_from'], $timezone)->startOfDay()->utc();
            $query->where('created_at', '>=', $from);
        }
        if (! empty($filters['date_to'])) {
            $to = CarbonImmutable::parse((string) $filters['date_to'], $timezone)->addDay()->startOfDay()->utc();
            $query->where('created_at', '<', $to);
        }
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function ensureVerifiedCustomer(User $actor): void
    {
        if ($this->isCustomer($actor) && $actor->email_verified_at === null) {
            throw new AuthorizationException;
        }
    }

    private function isAdministrator(User $actor): bool
    {
        return in_array('admin', $actor->tyroRoleSlugs(), true);
    }

    private function isAgent(User $actor): bool
    {
        return in_array('agent', $actor->tyroRoleSlugs(), true);
    }

    private function isCustomer(User $actor): bool
    {
        return in_array('customer', $actor->tyroRoleSlugs(), true);
    }
}
