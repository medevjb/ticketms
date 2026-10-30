<?php

namespace App\Enums;

enum TicketBulkAction: string
{
    case Enable = 'enable';
    case Disable = 'disable';
    case Archive = 'archive';
    case Restore = 'restore';
}
