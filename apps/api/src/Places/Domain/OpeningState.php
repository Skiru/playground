<?php

declare(strict_types=1);

namespace App\Places\Domain;

enum OpeningState: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case UNKNOWN = 'unknown';
}
