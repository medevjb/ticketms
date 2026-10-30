<?php

namespace App\Enums;

enum CategoryBulkAction: string
{
    case Enable = 'enable';
    case Disable = 'disable';
    case Archive = 'archive';
    case Restore = 'restore';
}
