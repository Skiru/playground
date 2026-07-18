<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class UploadPlacePhotos
{
    /**
     * @param list<\Symfony\Component\HttpFoundation\File\UploadedFile> $files
     */
    public function __construct(public string $placeId, public array $files)
    {
    }
}
