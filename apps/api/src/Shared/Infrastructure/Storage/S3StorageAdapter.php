<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;
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
                throw new \InvalidArgumentException('S3 bucket name is required.');
            }
            if (empty($this->region)) {
                throw new \InvalidArgumentException('S3 region is required.');
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

        $this->getClient()->putObject($params);
    }

    public function delete(string $path): void
    {
        try {
            $key = new StorageObjectKey($path);
            $this->getClient()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key->toString(),
            ]);
        } catch (\InvalidArgumentException) {
            // Idempotent delete for invalid formats
        }
    }

    public function read(string $path): string
    {
        $key = new StorageObjectKey($path);
        $result = $this->getClient()->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
        ]);

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

        return $this->getClient()->getObjectUrl($this->bucket ?? '', $key->toString());
    }
}
