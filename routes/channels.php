<?php

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return $user->getKey() === $userId && $user->is_active && ! $user->trashed();
});

Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId): bool {
    if (! $user->is_active || $user->trashed()) {
        return false;
    }

    $ticket = Ticket::query()->whereKey($ticketId)->first();
    if ($ticket === null || ! $ticket->is_active) {
        return false;
    }

    if ((int) $ticket->customer_id === (int) $user->getKey()) {
        return $user->hasVerifiedEmail() && in_array('customer', $user->tyroRoleSlugs(), true);
    }

    $roles = $user->tyroRoleSlugs();
    if (in_array('admin', $roles, true) && $user->can('tickets.view')) {
        return true;
    }

    return (int) $ticket->assigned_agent_id === (int) $user->getKey()
        && in_array('agent', $roles, true)
        && $user->can('tickets.view');
});
