<?php

namespace App\Enums;

enum BulkItemResult: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Conflict = 'conflict';
    case Unavailable = 'unavailable';
}
