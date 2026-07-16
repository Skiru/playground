<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Application\Dto\MapPlaceFeature;
use App\Discovery\Application\Dto\PlaceDetails;
use App\Discovery\Application\Dto\PlaceListItem;

interface PlaceReadModel
{
    /** @return array{items: list<PlaceListItem>, total: int} */
    public function search(PlaceSearchQuery $query): array;

    /** @return list<array<string, mixed>> */
    public function referenceData(string $table): array;

    public function details(string $slug): ?PlaceDetails;

    /** @return array{features: list<MapPlaceFeature>, truncated: bool} */
    public function map(float $west, float $south, float $east, float $north, PlaceSearchQuery $query): array;
}
