<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final readonly class DuplicateAssessment
{
    /**
     * @param list<string> $reasons
     * @param list<string> $placeIds
     * @param list<string> $candidateIds
     */
    public function __construct(public int $score, public array $reasons, public array $placeIds, public array $candidateIds = [])
    {
    }
}
