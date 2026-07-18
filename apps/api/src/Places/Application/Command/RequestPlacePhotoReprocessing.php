<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class RequestPlacePhotoReprocessing
{
    public function __construct(public string $placeId, public string $photoId)
    {
    }
}
