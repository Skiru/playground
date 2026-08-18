<?php

declare(strict_types=1);

namespace App\Tests\Identity\UI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleAuthRegressionTest extends WebTestCase
{
    public function testGoogleAuthEndpointIsReachableAndNot404(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/auth/google', [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['credential' => 'sample-invalid-google-token']));

        $response = $client->getResponse();
        self::assertNotEquals(404, $response->getStatusCode(), 'POST /api/v1/auth/google returned 404 Not Found');
        self::assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('GOOGLE_CREDENTIAL_INVALID', $data['code'] ?? null);
    }

    public function testGoogleAuthRejectsUntrustedOrigin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/auth/google', [], [], [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_ORIGIN' => 'https://malicious-attacker.com',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['credential' => 'sample-google-token']));

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('GOOGLE_CREDENTIAL_INVALID', $data['code'] ?? null);
    }

    public function testGoogleAuthRejectsInvalidContentType(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/auth/google', [], [], [
            'REMOTE_ADDR' => '10.0.0.3',
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'CONTENT_TYPE' => 'text/plain',
        ], 'credential=sample-google-token');

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('GOOGLE_CREDENTIAL_INVALID', $data['code'] ?? null);
    }

    public function testGoogleAuthRejectsOversizedPayload(): void
    {
        $client = self::createClient();
        $largeCredential = str_repeat('a', 10000);
        $client->request('POST', '/api/v1/auth/google', [], [], [
            'REMOTE_ADDR' => '10.0.0.4',
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['credential' => $largeCredential]));

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('GOOGLE_CREDENTIAL_INVALID', $data['code'] ?? null);
    }

    public function testGoogleAuthRejectsMissingCredential(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/auth/google', [], [], [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('GOOGLE_CREDENTIAL_INVALID', $data['code'] ?? null);
    }

    public function testGoogleAuthRateThrottling(): void
    {
        $client = self::createClient();
        $ip = '10.0.0.6';

        // 5 requests allowed
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/v1/auth/google', [], [], [
                'REMOTE_ADDR' => $ip,
                'HTTP_ORIGIN' => 'http://localhost:5173',
                'CONTENT_TYPE' => 'application/json',
            ], json_encode(['credential' => 'token-'.$i]));
            self::assertSame(401, $client->getResponse()->getStatusCode());
        }

        // 6th request triggers rate limit 429
        $client->request('POST', '/api/v1/auth/google', [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['credential' => 'token-overflow']));

        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('AUTH_RATE_LIMITED', $data['code'] ?? null);
        self::assertTrue($response->headers->has('Retry-After'));
    }
}
