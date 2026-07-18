<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;

final class LocalStorageAdapter implements StorageInterface
{
    private string $storageDir;
    private string $baseUrl;

    public function __construct(string $storageDir, string $baseUrl)
    {
        $this->storageDir = rtrim($storageDir, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    private function getFullPath(string $path): string
    {
        $key = new StorageObjectKey($path);
        $isSource = !str_contains($key->toString(), '/variants/');
        $subDir = $isSource ? 'private' : 'public';

        $fullPath = $this->storageDir.'/'.$subDir.'/'.$key->toString();

        if (str_contains($fullPath, '..')) {
            throw new \InvalidArgumentException('Path traversal attempt detected.');
        }

        return $fullPath;
    }

    public function write(string $path, string $contents): void
    {
        $fullPath = $this->getFullPath($path);
        $dir = \dirname($fullPath);

        if (!is_dir($dir)) {
            $mkdirResult = mkdir($dir, 0755, true);
            if (false === $mkdirResult && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Failed to create directory "%s".', $dir));
            }
        }

        $tempFile = $fullPath.'.'.bin2hex(random_bytes(8)).'.tmp';

        $stream = fopen($tempFile, 'w');
        if (false === $stream) {
            throw new \RuntimeException(\sprintf('Failed to open temporary file "%s" for writing.', $tempFile));
        }

        $writeResult = fwrite($stream, $contents);
        fclose($stream);

        if (false === $writeResult || \strlen($contents) !== $writeResult) {
            unlink($tempFile);
            throw new \RuntimeException(\sprintf('Failed to write complete contents to temporary file "%s".', $tempFile));
        }

        if (!chmod($tempFile, 0644)) {
            unlink($tempFile);
            throw new \RuntimeException(\sprintf('Failed to chmod temporary file "%s".', $tempFile));
        }

        if (!rename($tempFile, $fullPath)) {
            unlink($tempFile);
            throw new \RuntimeException(\sprintf('Failed to atomically rename "%s" to "%s".', $tempFile, $fullPath));
        }
    }

    public function delete(string $path): void
    {
        try {
            $fullPath = $this->getFullPath($path);
            if (is_file($fullPath)) {
                if (!unlink($fullPath)) {
                    throw new \RuntimeException(\sprintf('Failed to delete file "%s".', $fullPath));
                }
            }
        } catch (\InvalidArgumentException) {
            // Idempotent delete for invalid formats
        }
    }

    public function read(string $path): string
    {
        $fullPath = $this->getFullPath($path);
        if (!is_file($fullPath)) {
            throw new \RuntimeException(\sprintf('File "%s" not found in local storage.', $path));
        }

        $stream = fopen($fullPath, 'r');
        if (false === $stream) {
            throw new \RuntimeException(\sprintf('Failed to open file "%s" for reading.', $fullPath));
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Failed to read contents of file "%s".', $fullPath));
        }

        return $contents;
    }

    public function getUrl(string $path): string
    {
        $key = new StorageObjectKey($path);
        if (!str_contains($key->toString(), '/variants/')) {
            throw new \RuntimeException('Private sources do not have a public URL.');
        }

        return $this->baseUrl.'/'.$key->toString();
    }
}
