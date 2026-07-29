<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;

final readonly class DiscoveryClassification
{
    /** @param list<string> $reasons */
    public function __construct(public CandidateStatus $status, public int $score, public ?string $category, public array $reasons, public bool $discoverable = true)
    {
    }
}
