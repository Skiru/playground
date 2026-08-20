<?php

declare(strict_types=1);

namespace App\Tests\Identity\UI\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GoogleLoginProxyRateLimiterTest extends WebTestCase
{
    public function testRateLimiterIsIsolatedPerClientIpThroughProxy(): void
    {
        $client = static::createClient();

        $clientA_Ip = '198.51.100.10';
        $clientB_Ip = '198.51.100.20';
        $origin = 'http://localhost:3000';

        // 1. Send 5 requests from Client A (limit is 5 per minute)
        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/v1/auth/google',
                [],
                [],
                [
                    'HTTP_ORIGIN' => $origin,
                    'HTTP_X_FORWARDED_FOR' => $clientA_Ip,
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_CONTENT_TYPE' => 'application/json',
                ],
                (string) json_encode(['credential' => 'token-a-'.$i])
            );

            // Should be 401 (invalid credential), NOT 429
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode(), "Request {$i} for Client A should be 401");
        }

        // 2. 6th request from Client A should be rate limited (429)
        $client->request(
            'POST',
            '/api/v1/auth/google',
            [],
            [],
            [
                'HTTP_ORIGIN' => $origin,
                'HTTP_X_FORWARDED_FOR' => $clientA_Ip,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CONTENT_TYPE' => 'application/json',
            ],
            (string) json_encode(['credential' => 'token-a-overflow'])
        );
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode(), 'Client A should be rate limited on 6th request');

        // 3. Request from Client B (different IP) should NOT be rate limited!
        $client->request(
            'POST',
            '/api/v1/auth/google',
            [],
            [],
            [
                'HTTP_ORIGIN' => $origin,
                'HTTP_X_FORWARDED_FOR' => $clientB_Ip,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CONTENT_TYPE' => 'application/json',
            ],
            (string) json_encode(['credential' => 'token-b-1'])
        );
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode(), 'Client B should NOT be affected by Client A rate limit');
    }
}
