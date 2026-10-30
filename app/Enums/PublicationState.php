<?php

namespace App\Enums;

enum PublicationState: string
{
    case Draft = 'draft';
    case Published = 'published';
}
