<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
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
    ) {
    }

    private function getClient(): S3Client
    {
        if (null === $this->s3Client) {
            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region' => $this->region ?? 'us-east-1',
                'endpoint' => $this->endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => $this->key,
                    'secret' => $this->secret,
                ],
            ]);
        }

        return $this->s3Client;
    }

    public function write(string $path, string $contents): void
    {
        $this->getClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($path, '/'),
            'Body' => $contents,
        ]);
    }

    public function delete(string $path): void
    {
        $this->getClient()->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($path, '/'),
        ]);
    }

    public function read(string $path): string
    {
        $result = $this->getClient()->getObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($path, '/'),
        ]);

        return (string) $result['Body'];
    }

    public function getUrl(string $path): string
    {
        if ($this->publicUrl) {
            return rtrim($this->publicUrl, '/').'/'.ltrim($path, '/');
        }

        return $this->getClient()->getObjectUrl($this->bucket ?? '', ltrim($path, '/'));
    }
}
