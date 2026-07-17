<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\DependencyInjection;

use App\Identity\Domain\User;
use App\Personalization\Domain\Favorite;
use App\Personalization\Domain\Visit;
use App\Places\Domain\ValueObject\Coordinates;
use App\Shared\Application\Clock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ServiceRegistrationTest extends KernelTestCase
{
    public function testApplicationPortsAreAliasedButDomainValuesAreNotServices(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(Clock::class));
        self::assertFalse($container->has(Coordinates::class));
        self::assertFalse($container->has(User::class));
        self::assertFalse($container->has(Favorite::class));
        self::assertFalse($container->has(Visit::class));
    }
}
