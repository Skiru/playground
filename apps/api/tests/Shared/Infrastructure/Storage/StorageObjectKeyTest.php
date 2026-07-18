<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageObjectKey;
use PHPUnit\Framework\TestCase;

final class StorageObjectKeyTest extends TestCase
{
    public function testValidSourceKey(): void
    {
        $placeId = '11111111-1111-1111-1111-111111111111';
        $photoId = '22222222-2222-2222-2222-222222222222';
        $key = StorageObjectKey::source($placeId, $photoId);

        self::assertSame(\sprintf('places/%s/photos/%s/source', $placeId, $photoId), $key->toString());
    }

    public function testValidVariantKey(): void
    {
        $placeId = '11111111-1111-1111-1111-111111111111';
        $photoId = '22222222-2222-2222-2222-222222222222';
        $key = StorageObjectKey::variant($placeId, $photoId, 3, 'hero');

        self::assertSame(\sprintf('places/%s/photos/%s/variants/3/hero.webp', $placeId, $photoId), $key->toString());
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageObjectKey('places/../photos/source');
    }

    public function testRejectsBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageObjectKey('places\\123\\photos');
    }

    public function testRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageObjectKey("places/\0/source");
    }

    public function testRejectsEmptySegments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageObjectKey('places//photos/source');
    }

    public function testRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageObjectKey('places/123/photos/456/invalid');
    }
}
