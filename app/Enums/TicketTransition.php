<?php

namespace App\Enums;

enum TicketTransition: string
{
    case Start = 'start';
    case Wait = 'wait';
    case Resume = 'resume';
    case Resolve = 'resolve';
    case Close = 'close';
    case Reopen = 'reopen';
}
