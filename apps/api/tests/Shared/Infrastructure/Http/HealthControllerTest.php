<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class HealthControllerTest extends WebTestCase
{
    public function testLivenessDoesNotRequireDependencies(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/health/live');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertResponseHasHeader('X-Correlation-ID');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }

    public function testInvalidCorrelationIdIsNotReflected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/health/live', server: ['HTTP_X_CORRELATION_ID' => "invalid\nvalue"]);

        self::assertResponseIsSuccessful();
        self::assertNotSame("invalid\nvalue", $client->getResponse()->headers->get('X-Correlation-ID'));
        $generated = (string) $client->getResponse()->headers->get('X-Correlation-ID');
        self::assertTrue(Uuid::isValid($generated));
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($generated));
    }

    public function testValidCorrelationIdIsPreserved(): void
    {
        $client = self::createClient();
        $correlationId = Uuid::v7()->toRfc4122();
        $client->request('GET', '/api/v1/health/live', server: ['HTTP_X_CORRELATION_ID' => $correlationId]);

        self::assertResponseHeaderSame('X-Correlation-ID', $correlationId);
    }
}
