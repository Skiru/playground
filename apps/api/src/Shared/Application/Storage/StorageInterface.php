<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

interface StorageInterface
{
    /**
     * Write contents to a path in storage.
     */
    public function write(string $path, string $contents): void;

    /**
     * Delete a file from storage.
     */
    public function delete(string $path): void;

    /**
     * Read file contents from storage.
     */
    public function read(string $path): string;

    /**
     * Get the public URL for a given path.
     */
    public function getUrl(string $path): string;
}
