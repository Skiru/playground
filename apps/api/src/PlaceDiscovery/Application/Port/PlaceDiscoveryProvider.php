<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Port;

use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\ProviderPlace;

interface PlaceDiscoveryProvider
{
    public function getProviderName(): string;

    public function getLatestRelease(): string;

    public function assertReleaseAvailable(string $release): void;

    /** @return iterable<ProviderPlace> */
    public function streamPlaces(DiscoveryArea $area, string $profile, string $release, int $limit): iterable;
}
