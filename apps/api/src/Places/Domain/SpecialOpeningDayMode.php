<?php

declare(strict_types=1);

namespace App\Places\Domain;

enum SpecialOpeningDayMode: string
{
    case CLOSED = 'closed';
    case OPEN_24_HOURS = 'open_24_hours';
    case CUSTOM = 'custom';
}
