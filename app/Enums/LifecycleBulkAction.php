<?php

namespace App\Enums;

enum LifecycleBulkAction: string
{
    case Enable = 'enable';
    case Disable = 'disable';
    case Archive = 'archive';
    case Restore = 'restore';
}
