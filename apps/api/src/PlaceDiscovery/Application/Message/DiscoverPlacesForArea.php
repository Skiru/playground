<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Message;

final readonly class DiscoverPlacesForArea
{
    public function __construct(public string $runId)
    {
        if (!\Symfony\Component\Uid\Uuid::isValid($runId)) {
            throw new \InvalidArgumentException('Discovery run id must be a UUID.');
        }
    }
}
