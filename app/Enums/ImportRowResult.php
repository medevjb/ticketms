<?php

namespace App\Enums;

enum ImportRowResult: string
{
    case Created = 'created';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
}
