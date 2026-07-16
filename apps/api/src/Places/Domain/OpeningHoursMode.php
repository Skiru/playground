<?php

declare(strict_types=1);

namespace App\Places\Domain;

enum OpeningHoursMode: string
{
    case UNKNOWN = 'unknown';
    case SCHEDULED = 'scheduled';
    case ALWAYS_OPEN = 'always_open';
}
