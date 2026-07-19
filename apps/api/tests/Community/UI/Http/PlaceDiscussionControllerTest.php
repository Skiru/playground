<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class PlaceDiscussionControllerTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private function createUser(string $email, string $displayName): User
    {
        $user = new User(
            email: new \App\Identity\Domain\ValueObject\EmailAddress($email),
            displayName: $displayName,
            createdAt: new \DateTimeImmutable(),
            roles: ['ROLE_USER']
        );
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testCommentsFullWorkflow(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $user = $this->createUser(\sprintf('test-discussion-%d@example.com', random_int(10000, 99999)), 'Test Discussion User');
        $client->loginUser($user);

        // Fetch two distinct published places
        $places = $this->em->getConnection()->fetchAllAssociative('SELECT id FROM places WHERE status = \'published\' LIMIT 2');
        self::assertCount(2, $places);
        $placeId1 = $places[0]['id'];
        $placeId2 = $places[1]['id'];

        // Request session to get CSRF token
        $client->request('GET', '/api/v1/session');
        self::assertResponseIsSuccessful();
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = [
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json'
        ];

        // Clean existing comments for both places
        $this->em->getConnection()->executeStatement('DELETE FROM place_comments WHERE place_id IN (:p1, :p2)', ['p1' => $placeId1, 'p2' => $placeId2]);

        // 1. Add top-level comment to place 1
        $client->request('POST', \sprintf('/api/v1/places/%s/comments', $placeId1), [], [], $csrfHeaders, json_encode([
            'body' => 'Ten plac zabaw jest świetny!',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $commentData = json_decode($client->getResponse()->getContent(), true);
        $commentId = $commentData['id'];

        // 2. Reply to comment (valid, level 1)
        $client->request('POST', \sprintf('/api/v1/place-comments/%s/replies', $commentId), [], [], $csrfHeaders, json_encode([
            'body' => 'Zgadzam się, karuzela jest super!',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $replyData = json_decode($client->getResponse()->getContent(), true);
        $replyId = $replyData['id'];

        // 3. Reject nesting reply deeper than level 1 (reply to reply must fail!)
        $client->request('POST', \sprintf('/api/v1/place-comments/%s/replies', $replyId), [], [], $csrfHeaders, json_encode([
            'body' => 'Próba dodania odpowiedzi trzeciego stopnia, która musi się nie udać.',
        ]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('COMMENT_REPLY_DEPTH_LIMIT', $client->getResponse()->getContent());

        // 4. Update own comment
        $client->request('PATCH', \sprintf('/api/v1/me/place-comments/%s', $commentId), [], [], $csrfHeaders, json_encode([
            'body' => 'Edytowany tekst głównego komentarza.',
            'version' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $updatedData = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Edytowany tekst głównego komentarza.', $updatedData['body']);

        // 5. Delete parent (soft delete) - child reply remains visible/readable
        $client->request('DELETE', \sprintf('/api/v1/me/place-comments/%s', $commentId), [], [], $csrfHeaders);
        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        // 6. Verify listing shows both parent (marked deleted) and child reply (visible)
        $client->request('GET', \sprintf('/api/v1/places/%s/comments', $placeId1));
        self::assertResponseIsSuccessful();
        $list = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(2, $list['items']);
    }
}
