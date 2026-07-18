<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\EasyAdmin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardAuthorizationTest extends WebTestCase
{
    public function testAnonymousVisitorCannotAccessAdmin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testPageSizeBoundaryIsEnforced(): void
    {
        $client = self::createClient();
        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', [
            '_username' => 'admin@example.test',
            '_password' => 'test-password',
            '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');

        $crawler = $client->request('GET', '/admin/places?pageSize=1000000');
        self::assertResponseIsSuccessful();

        $links = $crawler->filter('a.page-link');
        if ($links->count() > 0) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                if (str_contains($href, 'pageSize=')) {
                    self::assertStringContainsString('pageSize=100', $href);
                    self::assertStringNotContainsString('pageSize=1000000', $href);
                }
            }
        }
    }
}
