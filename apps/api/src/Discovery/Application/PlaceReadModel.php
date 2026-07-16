<?php

declare(strict_types=1);

namespace App\Discovery\Application;

interface PlaceReadModel
{
    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(PlaceSearchQuery $query): array;

    /** @return list<array<string, mixed>> */
    public function referenceData(string $table): array;

    /** @return array<string, mixed>|null */
    public function details(string $slug): ?array;

    /** @return array{features: list<array<string, mixed>>, truncated: bool} */
    public function map(float $west, float $south, float $east, float $north, PlaceSearchQuery $query): array;
}
