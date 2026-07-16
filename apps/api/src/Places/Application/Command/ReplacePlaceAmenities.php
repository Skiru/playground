<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class ReplacePlaceAmenities
{
    public function __construct(
        public string $placeId,
        public int $expectedVersion,
        /** @var list<string> */
        public array $amenitySlugs,
    ) {
        if ($expectedVersion < 1) {
            throw new \InvalidArgumentException('Expected version must be positive.');
        }
    }
}
