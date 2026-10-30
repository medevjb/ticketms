<?php

namespace App\Enums;

enum SlaIntervalKind: string
{
    case Waiting = 'waiting';
    case Resolved = 'resolved';
}
