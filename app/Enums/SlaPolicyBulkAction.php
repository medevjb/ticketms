<?php

namespace App\Enums;

enum SlaPolicyBulkAction: string
{
    case Enable = 'enable';
    case Disable = 'disable';
    case Archive = 'archive';
    case Restore = 'restore';
}
