<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

final class ImageProcessor
{
    /**
     * Resizes an image to a given target width, maintaining aspect ratio, and returns compressed WebP bytes.
     */
    public function resizeToWebp(string $imageBytes, int $targetWidth, int $quality = 80): string
    {
        $image = @imagecreatefromstring($imageBytes);
        if (false === $image) {
            throw new \RuntimeException('Failed to parse image from bytes.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($targetWidth <= 0) {
            $targetWidth = 100;
        }

        if ($width <= $targetWidth) {
            // No need to upscale, just convert original size to WebP
            $targetWidth = $width;
            $targetHeight = $height;
        } else {
            $targetHeight = (int) round($height * ($targetWidth / $width));
        }

        if ($targetHeight <= 0) {
            $targetHeight = 100;
        }

        $newImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if (false === $newImage) {
            imagedestroy($image);
            throw new \RuntimeException('Failed to create new true-color GD image.');
        }

        // Preserve transparency
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        if (false !== $transparent) {
            imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $success = imagewebp($newImage, null, $quality);
        $outputBytes = ob_get_clean();

        imagedestroy($image);
        imagedestroy($newImage);

        if (!$success || !\is_string($outputBytes)) {
            throw new \RuntimeException('Failed to generate WebP image.');
        }

        return $outputBytes;
    }
}
