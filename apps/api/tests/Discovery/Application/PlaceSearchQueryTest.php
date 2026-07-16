<?php

declare(strict_types=1);

namespace App\Tests\Discovery\Application;

use App\Discovery\Application\PlaceSearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PlaceSearchQueryTest extends TestCase
{
    /** @param array<string, string> $query */
    #[DataProvider('invalidQueries')]
    public function testItRejectsInvalidBoundaries(array $query): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PlaceSearchQuery::fromRequest(new Request($query));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidQueries(): iterable
    {
        yield 'age' => [['ageMonths' => '217']];
        yield 'radius' => [['latitude' => '52', 'longitude' => '21', 'radiusKm' => '101']];
        yield 'axis pair' => [['latitude' => '52']];
        yield 'page size' => [['pageSize' => '51']];
        yield 'sort allowlist' => [['sort' => 'published_at desc']];
    }

    public function testAmenitiesRemainAnAndFilterList(): void
    {
        $query = PlaceSearchQuery::fromRequest(new Request(['amenities' => ['parking', 'wifi']]));
        self::assertSame(['parking', 'wifi'], $query->amenities);
    }

    public function testSingleAmenityFromGeneratedClientIsNormalizedToAList(): void
    {
        $query = PlaceSearchQuery::fromRequest(new Request(['amenities' => 'parking']));
        self::assertSame(['parking'], $query->amenities);
    }
}
