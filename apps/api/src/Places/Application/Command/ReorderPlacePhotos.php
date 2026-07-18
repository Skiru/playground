<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class ReorderPlacePhotos
{
    /**
     * @param list<string> $photoIds
     */
    public function __construct(public string $placeId, public array $photoIds)
    {
    }
}
