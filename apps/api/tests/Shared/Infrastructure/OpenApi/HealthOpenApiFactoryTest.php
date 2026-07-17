<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HealthOpenApiFactoryTest extends KernelTestCase
{
    public function testHealthOperationsArePartOfThePublicContract(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        self::assertInstanceOf(OpenApiFactoryInterface::class, $factory);

        $paths = $factory()->getPaths()->getPaths();

        self::assertArrayHasKey('/api/v1/health/live', $paths);
        self::assertArrayHasKey('/api/v1/health/ready', $paths);
    }
}
