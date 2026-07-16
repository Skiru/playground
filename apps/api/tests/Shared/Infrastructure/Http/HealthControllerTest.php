<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
    }
}
