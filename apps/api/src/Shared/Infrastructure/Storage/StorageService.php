<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class StorageService implements StorageInterface
{
    private StorageInterface $adapter;

    public function __construct(
        LocalStorageAdapter $local,
        S3StorageAdapter $s3,
        #[Autowire('%env(default::STORAGE_DRIVER)%')] string $driver,
    ) {
        $this->adapter = 's3' === strtolower($driver) ? $s3 : $local;
    }

    public function write(string $path, string $contents): void
    {
        $this->adapter->write($path, $contents);
    }

    public function delete(string $path): void
    {
        $this->adapter->delete($path);
    }

    public function read(string $path): string
    {
        return $this->adapter->read($path);
    }

    public function getUrl(string $path): string
    {
        return $this->adapter->getUrl($path);
    }
}
