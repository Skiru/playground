<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Message;

final readonly class DiscoverPlacesForArea
{
    public function __construct(public string $areaId, public string $release, public int $attempt = 1)
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Discovery attempt must be positive.');
        }
    }
}
