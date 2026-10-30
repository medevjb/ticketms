<?php

namespace App\Enums;

enum PriorityBulkAction: string
{
    case Enable = 'enable';
    case Disable = 'disable';
    case Archive = 'archive';
    case Restore = 'restore';
}
