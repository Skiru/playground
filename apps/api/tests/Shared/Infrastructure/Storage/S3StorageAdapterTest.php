<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Storage;

use App\Shared\Infrastructure\Storage\S3StorageAdapter;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

final class S3StorageAdapterTest extends TestCase
{
    public function testConstructThrowsOnMissingBucket(): void
    {
        $adapter = new S3StorageAdapter(
            endpoint: 'http://localhost',
            key: 'key',
            secret: 'secret',
            bucket: '',
            region: 'us-east-1'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 bucket name is required.');

        // Trigger client creation
        $adapter->write('places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/source', 'content');
    }

    public function testConstructThrowsOnMissingRegion(): void
    {
        $adapter = new S3StorageAdapter(
            endpoint: 'http://localhost',
            key: 'key',
            secret: 'secret',
            bucket: 'my-bucket',
            region: ''
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 region is required.');

        // Trigger client creation
        $adapter->write('places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/source', 'content');
    }

    public function testGetUrlThrowsForPrivateSource(): void
    {
        $adapter = new S3StorageAdapter(
            endpoint: 'http://localhost',
            key: 'key',
            secret: 'secret',
            bucket: 'my-bucket',
            region: 'us-east-1'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Private sources do not have a public URL.');

        $adapter->getUrl('places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/source');
    }

    public function testGetUrlReturnsPublicUrlForVariant(): void
    {
        $adapter = new S3StorageAdapter(
            endpoint: 'http://localhost',
            key: 'key',
            secret: 'secret',
            bucket: 'my-bucket',
            region: 'us-east-1',
            publicUrl: 'https://cdn.example.com'
        );

        $url = $adapter->getUrl('places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/variants/1/card.webp');
        self::assertSame('https://cdn.example.com/places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/variants/1/card.webp', $url);
    }
}
