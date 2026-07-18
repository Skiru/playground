<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class UpdatePlacePhotoMetadata
{
    public function __construct(
        public string $placeId,
        public string $photoId,
        public ?string $altText,
        public ?string $caption,
        public int $displayOrder,
    ) {
    }
}
