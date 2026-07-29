<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Port;

use App\PlaceDiscovery\Domain\DuplicateAssessment;
use App\PlaceDiscovery\Domain\NormalizedPlace;
use App\PlaceDiscovery\Domain\ProviderPlace;

interface DuplicatePlaceLookup
{
    public function assess(ProviderPlace $source, NormalizedPlace $normalized): DuplicateAssessment;
}
