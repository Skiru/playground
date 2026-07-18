<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class UploadPlacePhotosInput
{
    /** @var list<UploadedPlaceImage> */
    private array $images;

    /**
     * @param list<UploadedFile> $files
     */
    public function __construct(array $files)
    {
        $images = [];
        foreach ($files as $file) {
            $images[] = new UploadedPlaceImage($file);
        }
        $this->images = $images;
    }

    /**
     * @return list<UploadedPlaceImage>
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * Validates all files.
     * Returns an array of error messages, keyed by the 0-based file index.
     * If validation passes, the returned array is empty.
     *
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->images)) {
            return [0 => 'No files uploaded.'];
        }

        // Max 10 files per request
        if (\count($this->images) > 10) {
            return [0 => 'Maximum of 10 files can be uploaded per request.'];
        }

        // Max 50 MB total per request
        $totalSize = 0;
        foreach ($this->images as $image) {
            $totalSize += $image->file()->getSize();
        }

        if ($totalSize > 50 * 1024 * 1024) {
            return [0 => 'Total upload size exceeds the limit of 50 MB.'];
        }

        // Validate each file
        foreach ($this->images as $index => $image) {
            $fileError = $image->validate();
            if ($fileError !== null) {
                $errors[$index] = $fileError;
            }
        }

        return $errors;
    }
}
