<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\Http;

use App\Places\Application\AdminPlaceGateway;
use App\Places\Application\ManagePlace;
use App\Tests\Shared\Application\FrozenClock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaceAdminAuthorizationTest extends WebTestCase
{
    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/places');
        self::assertResponseRedirects('/admin/login');
    }

    public function testAdministratorCanOpenPlaceWorkflow(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');
        self::getContainer()->set(ManagePlace::class, new ManagePlace(new class implements AdminPlaceGateway {
            public function list(): array
            {
                return [];
            }

            public function createDraft(array $data): string
            {
                return 'unused';
            }

            public function publicationProblems(string $id): array
            {
                return [];
            }

            public function changeStatus(string $id, string $status, ?\DateTimeImmutable $publishedAt): void
            {
            }
        }, new FrozenClock(new \DateTimeImmutable('2026-07-16T08:00:00Z'))));
        $client->request('GET', '/admin/places');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Miejsca');
    }
}
