<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageConfigurationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class StorageService implements StorageInterface
{
    private StorageInterface $adapter;

    public function __construct(
        LocalStorageAdapter $local,
        S3StorageAdapter $s3,
        #[Autowire('%env(default::STORAGE_DRIVER)%')] string $driver,
        #[Autowire('%env(default::MEDIA_PUBLIC_BASE_URL)%')] ?string $mediaPublicBaseUrl,
        #[Autowire('%env(default::STORAGE_S3_PUBLIC_URL)%')] ?string $s3PublicUrl,
        #[Autowire('%env(APP_ENV)%')] string $appEnv,
    ) {
        $normalizedDriver = strtolower($driver);
        if ('local' !== $normalizedDriver && 's3' !== $normalizedDriver) {
            throw new \InvalidArgumentException(\sprintf('Invalid storage driver "%s". Allowed values are "local", "s3".', $driver));
        }

        if ('s3' === $normalizedDriver) {
            $this->validateUrl((string) $s3PublicUrl, 'STORAGE_S3_PUBLIC_URL', $appEnv);
        } else {
            $this->validateUrl((string) $mediaPublicBaseUrl, 'MEDIA_PUBLIC_BASE_URL', $appEnv);
        }

        $this->adapter = 's3' === $normalizedDriver ? $s3 : $local;
    }

    private function validateUrl(string $url, string $envVarName, string $appEnv): void
    {
        if (empty($url)) {
            throw new StorageConfigurationException(\sprintf('%s is required.', $envVarName));
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme']) || !isset($parts['host'])) {
            throw new StorageConfigurationException(\sprintf('%s has an invalid format.', $envVarName));
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ('https' !== $scheme && 'http' !== $scheme) {
            throw new StorageConfigurationException(\sprintf('%s scheme must be http or https.', $envVarName));
        }

        if ('127.0.0.1' === $host) {
            throw new StorageConfigurationException(\sprintf('%s host cannot be 127.0.0.1.', $envVarName));
        }

        if ('localhost' === $host && 'prod' === strtolower($appEnv)) {
            throw new StorageConfigurationException(\sprintf('%s host cannot be localhost in production.', $envVarName));
        }

        if ('http' === $scheme && 'prod' === strtolower($appEnv)) {
            throw new StorageConfigurationException(\sprintf('%s scheme must be https in production.', $envVarName));
        }
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
