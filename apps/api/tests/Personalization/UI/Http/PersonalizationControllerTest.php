<?php

declare(strict_types=1);

namespace App\Tests\Personalization\UI\Http;

use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PersonalizationControllerTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private function createUser(string $email, string $name): User
    {
        $user = new User(new EmailAddress($email), $name, new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testFavoritesAndVisitsWorkflow(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $userEmail = \sprintf('test-personalization-%d@example.com', random_int(10000, 99999));
        $user = $this->createUser($userEmail, 'Test Pers');
        $client->loginUser($user);

        // Fetch first published place from database
        $placeIdRow = $this->em->getConnection()->fetchAssociative('SELECT id FROM places WHERE status = \'published\' LIMIT 1');
        self::assertNotFalse($placeIdRow);
        $placeId = $placeIdRow['id'];

        // Request session to get actual CSRF token
        $client->request('GET', '/api/v1/session');
        self::assertResponseIsSuccessful();
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = ['HTTP_X-CSRF-Token' => $csrfToken];

        // 1. Add place to favorites
        $client->request('PUT', \sprintf('/api/v1/places/%s/favorite', $placeId), [], [], $csrfHeaders);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($placeId, $data['placeId']);

        // 2. Fetch favorites list
        $client->request('GET', '/api/v1/me/favorites');
        self::assertResponseIsSuccessful();
        $list = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $list['items']);
        self::assertSame($placeId, $list['items'][0]['placeId']);

        // 3. Batch state check
        $client->request('GET', '/api/v1/me/place-state?placeIds[]='.$placeId);
        self::assertResponseIsSuccessful();
        $state = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($state[$placeId]['favorite']);

        // 4. Create a visit record
        $client->request('POST', \sprintf('/api/v1/places/%s/visits', $placeId), [], [], $csrfHeaders, json_encode([
            'visitedOn' => '2026-07-17',
            'note' => 'Wspaniałe kawiarniane lody!',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $visitData = json_decode($client->getResponse()->getContent(), true);
        $visitId = $visitData['id'];

        // 5. Update visit record
        $client->request('PATCH', \sprintf('/api/v1/me/visits/%s', $visitId), [], [], $csrfHeaders, json_encode([
            'note' => 'Edytowana notatka',
        ]));
        self::assertResponseIsSuccessful();
        $updatedData = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Edytowana notatka', $updatedData['note']);

        // 6. Isolation/BOLA test: another user cannot access/edit/delete this visit
        $otherEmail = \sprintf('other-%d@example.com', random_int(10000, 99999));
        $otherUser = $this->createUser($otherEmail, 'Other User');
        $client->loginUser($otherUser);

        $client->request('PATCH', \sprintf('/api/v1/me/visits/%s', $visitId), [], [], $csrfHeaders, json_encode([
            'note' => 'Próba przejęcia!',
        ]));
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());

        // Restore original user
        $client->loginUser($user);

        // 7. Delete visit record
        $client->request('DELETE', \sprintf('/api/v1/me/visits/%s', $visitId), [], [], $csrfHeaders);
        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        // 8. Delete favorite
        $client->request('DELETE', \sprintf('/api/v1/places/%s/favorite', $placeId), [], [], $csrfHeaders);
        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());
    }
}
