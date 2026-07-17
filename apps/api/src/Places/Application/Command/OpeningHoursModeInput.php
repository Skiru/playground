<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

enum OpeningHoursModeInput: string
{
    case UNKNOWN = 'unknown';
    case SCHEDULED = 'scheduled';
    case ALWAYS_OPEN = 'always_open';
}
