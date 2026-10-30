<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
