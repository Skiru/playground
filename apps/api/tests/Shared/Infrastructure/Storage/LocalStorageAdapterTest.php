<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Storage;

use App\Shared\Infrastructure\Storage\LocalStorageAdapter;
use PHPUnit\Framework\TestCase;

final class LocalStorageAdapterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/media_test_'.bin2hex(random_bytes(4));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir.'/'.$file;
                is_dir($path) ? $this->removeDirectory($path) : unlink($path);
            }
            rmdir($dir);
        }
    }

    public function testWriteAndReadPrivateSource(): void
    {
        $adapter = new LocalStorageAdapter($this->tempDir, '/media');
        $placeId = '11111111-1111-1111-1111-111111111111';
        $photoId = '22222222-2222-2222-2222-222222222222';
        $path = \sprintf('places/%s/photos/%s/source', $placeId, $photoId);

        $adapter->write($path, 'test original content');

        // Check if saved inside the "private" subdirectory
        $expectedFile = $this->tempDir.'/private/'.$path;
        self::assertFileExists($expectedFile);
        self::assertSame('test original content', $adapter->read($path));

        // Check file permissions (0644)
        $perms = fileperms($expectedFile) & 0777;
        self::assertSame(0644, $perms);

        // Check directory permissions (0755)
        $dirPerms = fileperms(\dirname($expectedFile)) & 0777;
        self::assertSame(0755, $dirPerms);
    }

    public function testWriteAndReadPublicVariant(): void
    {
        $adapter = new LocalStorageAdapter($this->tempDir, '/media');
        $placeId = '11111111-1111-1111-1111-111111111111';
        $photoId = '22222222-2222-2222-2222-222222222222';
        $path = \sprintf('places/%s/photos/%s/variants/1/hero.webp', $placeId, $photoId);

        $adapter->write($path, 'test variant content');

        // Check if saved inside the "public" subdirectory
        $expectedFile = $this->tempDir.'/public/'.$path;
        self::assertFileExists($expectedFile);
        self::assertSame('test variant content', $adapter->read($path));

        // Public variant has public URL
        self::assertSame('/media/'.$path, $adapter->getUrl($path));
    }

    public function testGetUrlThrowsForPrivateSource(): void
    {
        $adapter = new LocalStorageAdapter($this->tempDir, '/media');
        $placeId = '11111111-1111-1111-1111-111111111111';
        $photoId = '22222222-2222-2222-2222-222222222222';
        $path = \sprintf('places/%s/photos/%s/source', $placeId, $photoId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Private sources do not have a public URL.');
        $adapter->getUrl($path);
    }

    public function testDeleteIsIdempotent(): void
    {
        $adapter = new LocalStorageAdapter($this->tempDir, '/media');
        $placeId = '11111111-1111-1111-1111-111111111111';
        $photoId = '22222222-2222-2222-2222-222222222222';
        $path = \sprintf('places/%s/photos/%s/source', $placeId, $photoId);

        // Deleting non-existent file is a no-op/idempotent
        $adapter->delete($path);
        self::assertTrue(true);

        $adapter->write($path, 'content');
        self::assertFileExists($this->tempDir.'/private/'.$path);

        $adapter->delete($path);
        self::assertFileDoesNotExist($this->tempDir.'/private/'.$path);

        // Multiple deletes do not fail
        $adapter->delete($path);
        self::assertTrue(true);
    }
}
