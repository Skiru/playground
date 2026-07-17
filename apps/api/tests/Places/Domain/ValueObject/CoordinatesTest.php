<?php

declare(strict_types=1);

namespace App\Tests\Places\Domain\ValueObject;

use App\Places\Domain\ValueObject\Coordinates;
use PHPUnit\Framework\TestCase;

final class CoordinatesTest extends TestCase
{
    public function testItKeepsLatitudeLongitudeOrderAcrossBoundaries(): void
    {
        $coordinates = new Coordinates(52.2297, 21.0122);

        self::assertSame('POINT(21.0122 52.2297)', $coordinates->toPostgisWkt());
        self::assertSame([21.0122, 52.2297], $coordinates->toMapLibre());
    }

    public function testItRejectsOutOfRangeCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Coordinates(91.0, 21.0);
    }
}
