<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;

final class LocalStorageAdapter implements StorageInterface
{
    private string $publicDir;
    private string $baseUrl;

    public function __construct(string $projectDir, string $baseUrl = '/uploads')
    {
        $this->publicDir = rtrim($projectDir, '/').'/public/uploads';
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function write(string $path, string $contents): void
    {
        $fullPath = $this->publicDir.'/'.ltrim($path, '/');
        $dir = \dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $contents);
    }

    public function delete(string $path): void
    {
        $fullPath = $this->publicDir.'/'.ltrim($path, '/');
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    public function read(string $path): string
    {
        $fullPath = $this->publicDir.'/'.ltrim($path, '/');
        if (!is_file($fullPath)) {
            throw new \RuntimeException(\sprintf('File "%s" not found in local storage.', $path));
        }

        $contents = file_get_contents($fullPath);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Failed to read file "%s" from local storage.', $path));
        }

        return $contents;
    }

    public function getUrl(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }
}
