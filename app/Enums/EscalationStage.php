<?php

namespace App\Enums;

enum EscalationStage: string
{
    case Approaching = 'approaching';
    case Breached = 'breached';
}
