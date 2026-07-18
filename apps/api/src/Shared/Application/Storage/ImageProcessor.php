<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

interface ImageProcessor
{
    /**
     * Resizes an image to a given target width, maintaining aspect ratio, and returns compressed WebP bytes.
     */
    public function resizeToWebp(string $imageBytes, int $targetWidth, int $quality = 80): string;
}
