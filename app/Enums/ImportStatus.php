<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
