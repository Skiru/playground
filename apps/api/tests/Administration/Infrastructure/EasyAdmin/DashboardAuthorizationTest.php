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
}
