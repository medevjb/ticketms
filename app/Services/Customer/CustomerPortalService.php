<?php

namespace App\Services\Customer;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class CustomerPortalService
{
    /** @return array{total: int, active: int, waiting: int, completed: int, recent: Collection<int, Ticket>} */
    public function dashboard(User $customer): array
    {
        return [
            'total' => $customer->ownedTickets()->count(),
            'active' => $customer->ownedTickets()
                ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
                ->count(),
            'waiting' => $customer->ownedTickets()
                ->where('status', TicketStatus::WaitingForCustomer)
                ->count(),
            'completed' => $customer->ownedTickets()
                ->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
                ->count(),
            'recent' => $customer->ownedTickets()
                ->with(['category', 'priority'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ];
    }
}
