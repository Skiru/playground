<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class UploadedPlaceImage
{
    public function __construct(private UploadedFile $file)
    {
    }

    public function file(): UploadedFile
    {
        return $this->file;
    }

    /**
     * @return string|null Error message if invalid, null if valid.
     */
    public function validate(): ?string
    {
        if (!$this->file->isValid()) {
            return 'File upload failed or was interrupted.';
        }

        // 12 MB limit per file
        if ($this->file->getSize() > 12 * 1024 * 1024) {
            return 'File size exceeds the limit of 12 MB.';
        }

        // Server-side MIME detection
        $pathname = $this->file->getPathname();
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (false === $finfo) {
            return 'Failed to open fileinfo database.';
        }
        $mime = finfo_file($finfo, $pathname);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            return \sprintf('Unsupported file type: %s. Allowed types are JPEG, PNG, and WebP.', $mime);
        }

        // GD/Image check
        $imageSize = @getimagesize($pathname);
        if (false === $imageSize) {
            return 'The file is not a valid image or is corrupted.';
        }

        [$width, $height, $type] = $imageSize;

        // Reject SVG, GIF, HTML, PDF and polyglots
        if (defined('IMAGETYPE_GIF') && $type === IMAGETYPE_GIF) {
            return 'GIF format is not allowed.';
        }
        
        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (defined('IMAGETYPE_WEBP')) {
            $allowedTypes[] = IMAGETYPE_WEBP;
        }

        if (!in_array($type, $allowedTypes, true)) {
            return 'Unsupported image format.';
        }

        // Max 40 megapixels
        if ($width * $height > 40000000) {
            return \sprintf('Image resolution (%dx%d = %.1f MP) exceeds the limit of 40 megapixels.', $width, $height, ($width * $height) / 1000000);
        }

        // Min 320x240 dimensions
        if ($width < 320 || $height < 240) {
            return \sprintf('Image dimensions (%dx%d) are smaller than the required minimum of 320x240.', $width, $height);
        }

        // Double check against potential polyglots by reading first 2KB for signatures
        $handle = fopen($pathname, 'rb');
        if ($handle) {
            $chunk = fread($handle, 2048);
            fclose($handle);
            if (false !== $chunk) {
                if (str_contains($chunk, '<svg') || str_contains($chunk, '<html') || str_contains($chunk, '<?php') || str_contains($chunk, '<?xml')) {
                    return 'Polyglot file signature detected and rejected.';
                }
            }
        }

        return null;
    }
}
