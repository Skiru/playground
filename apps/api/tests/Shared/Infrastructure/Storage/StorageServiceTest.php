<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageConfigurationException;
use App\Shared\Infrastructure\Storage\LocalStorageAdapter;
use App\Shared\Infrastructure\Storage\S3StorageAdapter;
use App\Shared\Infrastructure\Storage\StorageService;
use PHPUnit\Framework\TestCase;

final class StorageServiceTest extends TestCase
{
    private $localAdapter;
    private $s3Adapter;

    protected function setUp(): void
    {
        $this->localAdapter = new LocalStorageAdapter('/tmp', 'http://localhost');
        $this->s3Adapter = new S3StorageAdapter();
    }

    public function testProdWithHttpsPublicUrlPasses(): void
    {
        $service = new StorageService(
            $this->localAdapter,
            $this->s3Adapter,
            'local',
            'https://media.familyplaces.pl/media',
            null,
            'prod',
            'false'
        );

        self::assertInstanceOf(StorageService::class, $service);
    }

    public function testProdWithLocalhostAndE2EFalseFails(): void
    {
        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage('MEDIA_PUBLIC_BASE_URL host cannot be localhost or loopback in production.');

        new StorageService(
            $this->localAdapter,
            $this->s3Adapter,
            'local',
            'https://localhost/media',
            null,
            'prod',
            'false'
        );
    }

    public function testProdWithHttpAndE2EFalseFails(): void
    {
        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage('MEDIA_PUBLIC_BASE_URL scheme must be https in production.');

        new StorageService(
            $this->localAdapter,
            $this->s3Adapter,
            'local',
            'http://media.familyplaces.pl/media',
            null,
            'prod',
            'false'
        );
    }

    public function testProdWithLoopbackAndE2ETruePasses(): void
    {
        $service = new StorageService(
            $this->localAdapter,
            $this->s3Adapter,
            'local',
            'http://127.0.0.1:8080/media',
            null,
            'prod',
            'true'
        );

        self::assertInstanceOf(StorageService::class, $service);
    }

    public function testProdWithExternalHttpAndE2ETrueFails(): void
    {
        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage('MEDIA_PUBLIC_BASE_URL must be exactly http://127.0.0.1:8080/media in E2E mode.');

        new StorageService(
            $this->localAdapter,
            $this->s3Adapter,
            'local',
            'http://external-site.com/media',
            null,
            'prod',
            'true'
        );
    }

    public function testTestDevLocalhostPasses(): void
    {
        $service = new StorageService(
            $this->localAdapter,
            $this->s3Adapter,
            'local',
            'http://localhost:8080/media',
            null,
            'dev',
            'false'
        );

        self::assertInstanceOf(StorageService::class, $service);
    }
}
