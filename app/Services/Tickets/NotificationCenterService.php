<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationCenterService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    public function unreadCount(User $actor): int
    {
        if (! $actor->is_active || $actor->trashed() || ! $this->permissions->allows($actor, 'notifications.view')) {
            return 0;
        }

        return $this->scopedQuery($actor)->whereNull('read_at')->count();
    }

    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    public function paginate(User $actor): LengthAwarePaginator
    {
        return $this->scopedQuery($actor)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function markRead(User $actor, string $notificationId): void
    {
        $notification = $this->scopedQuery($actor)->whereKey($notificationId)->first();
        if (! $notification instanceof DatabaseNotification) {
            throw (new ModelNotFoundException)->setModel(DatabaseNotification::class, [$notificationId]);
        }

        $notification->markAsRead();
    }

    public function markAllRead(User $actor): void
    {
        $this->scopedQuery($actor)->whereNull('read_at')->update(['read_at' => CarbonImmutable::now('UTC')]);
    }

    /** @return MorphMany<DatabaseNotification, User> */
    private function scopedQuery(User $actor): MorphMany
    {
        if (! $actor->is_active || $actor->trashed() || ! $this->permissions->allows($actor, 'notifications.view')) {
            throw new AuthorizationException;
        }

        $query = $actor->notifications()->where('type', TicketActivityNotification::class);
        $roles = $actor->tyroRoleSlugs();
        $ticketScope = Ticket::query()->select('id');

        if (in_array('admin', $roles, true)) {
            return $query;
        }
        if (in_array('customer', $roles, true)) {
            if ($actor->email_verified_at === null) {
                return $query->whereRaw('1 = 0');
            }
            $ticketScope->where('customer_id', $actor->getKey());
        } elseif (in_array('agent', $roles, true)) {
            $ticketScope->where('assigned_agent_id', $actor->getKey());
        } else {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('data->ticket_id', $ticketScope);
    }
}
