<?php

declare(strict_types=1);

namespace App\Tests\Discovery\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiscoveryResourceLimitsTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejectedQueries(): iterable
    {
        yield 'page size' => ['/api/v1/places?pageSize=51'];
        yield 'page' => ['/api/v1/places?page=5001'];
        yield 'offset' => ['/api/v1/places?page=1002&pageSize=50'];
        yield 'query length' => ['/api/v1/places?q='.str_repeat('a', 101)];
        yield 'amenities' => ['/api/v1/places?'.http_build_query(['amenities' => range(1, 11)])];
        yield 'bbox area' => ['/api/v1/map/places?west=0&south=0&east=10&north=10&zoom=8'];
    }

    #[DataProvider('rejectedQueries')]
    public function testLimitsReturnProblemDetails(string $uri): void
    {
        $client = self::createClient();
        $client->request('GET', $uri);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('invalid_query', $payload['code']);
    }

    public function testMapFeatureCountIsHardLimited(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/map/places?west=20&south=51&east=22&north=53&zoom=8');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(500, \count($payload['features']));
    }
}
