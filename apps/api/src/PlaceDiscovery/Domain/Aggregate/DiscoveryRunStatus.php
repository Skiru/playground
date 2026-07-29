<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain\Aggregate;

enum DiscoveryRunStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
