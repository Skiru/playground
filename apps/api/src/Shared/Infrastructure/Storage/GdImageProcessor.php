<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\ImageProcessor;

final class GdImageProcessor implements ImageProcessor
{
    public function resizeToWebp(string $imageBytes, int $targetWidth, int $quality = 80): string
    {
        $image = imagecreatefromstring($imageBytes);
        if (false === $image) {
            throw new \RuntimeException('Failed to parse image from bytes.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width * $height > 40000000) {
            throw new \RuntimeException('Image resolution exceeds the limit of 40 megapixels.');
        }

        // Normalize EXIF orientation of JPEG if exif extension is available
        if (function_exists('exif_read_data')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'exif_');
            if (false !== $tmpFile) {
                file_put_contents($tmpFile, $imageBytes);
                $mime = mime_content_type($tmpFile);
                if ($mime === 'image/jpeg') {
                    $exif = exif_read_data($tmpFile);
                    if (is_array($exif) && isset($exif['Orientation'])) {
                        $orientation = (int) $exif['Orientation'];
                        switch ($orientation) {
                            case 3:
                                $rotated = imagerotate($image, 180, 0);
                                if (false !== $rotated) {
                                    $image = $rotated;
                                }
                                break;
                            case 6:
                                $rotated = imagerotate($image, -90, 0);
                                if (false !== $rotated) {
                                    $image = $rotated;
                                }
                                break;
                            case 8:
                                $rotated = imagerotate($image, 90, 0);
                                if (false !== $rotated) {
                                    $image = $rotated;
                                }
                                break;
                        }
                    }
                }
                unlink($tmpFile);
            }
        }

        // Re-read dimensions after possible rotation
        $width = imagesx($image);
        $height = imagesy($image);

        if ($targetWidth <= 0) {
            $targetWidth = 100;
        }

        if ($width <= $targetWidth) {
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
            throw new \RuntimeException('Failed to create new true-color GD image.');
        }

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

        if (!$success || !\is_string($outputBytes)) {
            throw new \RuntimeException('Failed to generate WebP image.');
        }

        return $outputBytes;
    }
}
