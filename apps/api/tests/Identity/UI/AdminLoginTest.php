<?php

declare(strict_types=1);

namespace App\Tests\Identity\UI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminLoginTest extends WebTestCase
{
    public function testLoginFormContainsCsrfAndPasswordFields(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="_csrf_token"]'));
        self::assertCount(1, $crawler->filter('input[name="_password"]'));
    }

    public function testLoginThrottlingLimiterIsConfigured(): void
    {
        self::bootKernel();
        self::assertTrue(self::getContainer()->has('security.login_throttling.main.limiter'));
    }
}
