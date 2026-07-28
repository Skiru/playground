<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain\Aggregate;

enum CandidateStatus: string
{
    case PENDING = 'PENDING';
    case NEEDS_MAPPING = 'NEEDS_MAPPING';
    case POSSIBLE_DUPLICATE = 'POSSIBLE_DUPLICATE';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case DUPLICATE = 'DUPLICATE';
    case STALE = 'STALE';
    case IMPORT_FAILED = 'IMPORT_FAILED';
}
