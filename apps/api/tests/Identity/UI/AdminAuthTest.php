<?php

declare(strict_types=1);

namespace App\Tests\Identity\UI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminAuthTest extends WebTestCase
{
    public function testAnonymousAccessToAdminIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        $response = $client->getResponse();
        self::assertTrue($response->isRedirection());
        self::assertStringContainsString('/admin/login', $response->headers->get('Location') ?? '');
    }

    public function testNormalUserAccessToAdminIsBlocked(): void
    {
        $client = self::createClient();
        $user = new InMemoryUser('user@example.test', 'password', ['ROLE_USER']);

        $client->loginUser($user);
        $client->request('GET', '/admin');

        $response = $client->getResponse();
        self::assertTrue($response->isRedirection() || 403 === $response->getStatusCode());
        if ($response->isRedirection()) {
            self::assertStringContainsString('/admin/login', $response->headers->get('Location') ?? '');
        }
    }

    public function testGoogleUserWithRoleUserIsBlockedFromAdmin(): void
    {
        $client = self::createClient();
        $googleUser = new InMemoryUser('google-user@example.test', null, ['ROLE_USER']);

        $client->loginUser($googleUser);
        $client->request('GET', '/admin');

        $response = $client->getResponse();
        self::assertTrue($response->isRedirection() || 403 === $response->getStatusCode());
        if ($response->isRedirection()) {
            self::assertStringContainsString('/admin/login', $response->headers->get('Location') ?? '');
        }
    }

    public function testAdminFormLoginWorkflow(): void
    {
        $client = self::createClient();
        $loginPage = $client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();

        $csrfToken = $loginPage->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($csrfToken);

        $client->request('POST', '/admin/login', [
            '_username' => 'admin@example.test',
            '_password' => 'test-password',
            '_csrf_token' => $csrfToken,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/admin');
    }
}
