<?php

namespace App\Enums;

enum MessageVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
