<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageConfigurationException;
use App\Shared\Application\Storage\StorageException;
use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;
use App\Shared\Application\Storage\StorageObjectNotFoundException;
use App\Shared\Application\Storage\TransientStorageException;
use Aws\S3\S3Client;

final class S3StorageAdapter implements StorageInterface
{
    private ?S3Client $s3Client = null;

    public function __construct(
        private ?string $endpoint = '',
        private ?string $key = '',
        private ?string $secret = '',
        private ?string $bucket = '',
        private ?string $region = 'us-east-1',
        private ?string $publicUrl = null,
        private bool $usePathStyleEndpoint = true,
    ) {
    }

    private function getClient(): S3Client
    {
        if (null === $this->s3Client) {
            if (empty($this->bucket)) {
                throw new StorageConfigurationException('S3 bucket name is required.');
            }
            if (empty($this->region)) {
                throw new StorageConfigurationException('S3 region is required.');
            }

            $config = [
                'version' => 'latest',
                'region' => $this->region,
                'use_path_style_endpoint' => $this->usePathStyleEndpoint,
            ];

            if (!empty($this->endpoint)) {
                $config['endpoint'] = $this->endpoint;
            }

            if (!empty($this->key) && !empty($this->secret)) {
                $config['credentials'] = [
                    'key' => $this->key,
                    'secret' => $this->secret,
                ];
            }

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }

    private function executeS3Call(callable $callback, string $path): mixed
    {
        try {
            return $callback($this->getClient());
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $statusCode = $e->getStatusCode();
            $errorCode = $e->getAwsErrorCode();
            if (404 === $statusCode || 'NoSuchKey' === $errorCode) {
                throw new StorageObjectNotFoundException(\sprintf('File "%s" not found in S3.', $path), 0, $e);
            }
            if (429 === $statusCode || ($statusCode >= 500 && $statusCode < 600) || 'RequestTimeout' === $errorCode || 'SlowDown' === $errorCode) {
                throw new TransientStorageException('Transient S3 storage error: '.$e->getMessage(), 0, $e);
            }
            if (403 === $statusCode || 'InvalidAccessKeyId' === $errorCode || 'SignatureDoesNotMatch' === $errorCode) {
                throw new StorageConfigurationException('S3 configuration or credential error: '.$e->getMessage(), 0, $e);
            }
            throw new StorageException('S3 storage error: '.$e->getMessage(), 0, $e);
        } catch (\GuzzleHttp\Exception\ConnectException|\GuzzleHttp\Exception\RequestException|\Aws\Exception\CredentialsException $e) {
            throw new TransientStorageException('Transient S3 connection error: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof StorageException) {
                throw $e;
            }
            throw new StorageException('Unexpected S3 error: '.$e->getMessage(), 0, $e);
        }
    }

    public function write(string $path, string $contents): void
    {
        $key = new StorageObjectKey($path);
        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'Body' => $contents,
        ];

        // Content-Type & Cache-Control for variants
        if (str_contains($key->toString(), '/variants/')) {
            $params['ContentType'] = 'image/webp';
            $params['CacheControl'] = 'max-age=31536000, public, immutable';
        }

        $this->executeS3Call(static function (S3Client $client) use ($params) {
            $client->putObject($params);
        }, $path);
    }

    public function delete(string $path): void
    {
        try {
            $key = new StorageObjectKey($path);
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
            ];

            $this->executeS3Call(static function (S3Client $client) use ($params) {
                $client->deleteObject($params);
            }, $path);
        } catch (\InvalidArgumentException) {
            // Idempotent delete for invalid formats
        } catch (StorageObjectNotFoundException) {
            // Missing source on delete is success/no-op
        }
    }

    public function read(string $path): string
    {
        $key = new StorageObjectKey($path);
        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
        ];

        $result = $this->executeS3Call(static function (S3Client $client) use ($params) {
            return $client->getObject($params);
        }, $path);

        return (string) $result['Body'];
    }

    public function getUrl(string $path): string
    {
        $key = new StorageObjectKey($path);
        if (!str_contains($key->toString(), '/variants/')) {
            throw new \RuntimeException('Private sources do not have a public URL.');
        }

        if ($this->publicUrl) {
            return rtrim($this->publicUrl, '/').'/'.ltrim($key->toString(), '/');
        }

        return $this->executeS3Call(function (S3Client $client) use ($key) {
            return $client->getObjectUrl($this->bucket ?? '', $key->toString());
        }, $path);
    }
}
