<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CommunityControllerTest extends WebTestCase
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

    public function testReviewsFullWorkflow(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $userEmail = \sprintf('test-community-%d@example.com', random_int(10000, 99999));
        $user = $this->createUser($userEmail, 'Test Comm');
        $client->loginUser($user);

        // Fetch first published place from database
        $placeIdRow = $this->em->getConnection()->fetchAssociative('SELECT id FROM places WHERE status = \'published\' LIMIT 1');
        self::assertNotFalse($placeIdRow);
        $placeId = $placeIdRow['id'];

        // Clean existing reviews for this place to isolate test
        $this->em->getConnection()->executeStatement('DELETE FROM reviews WHERE place_id = :place_id', ['place_id' => $placeId]);

        // Request session to get CSRF token
        $client->request('GET', '/api/v1/session');
        self::assertResponseIsSuccessful();
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = [
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];

        $client->request('POST', \sprintf('/api/v1/places/%s/reviews', $placeId), [], [], [
            'HTTP_X-CSRF-Token' => 'arbitrary-non-empty-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'rating' => 5,
            'body' => 'Ta próba ma nieprawidłowy, ale niepusty token zabezpieczający.',
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('CSRF_TOKEN_INVALID', $client->getResponse()->getContent());

        // 1. Create a review
        $client->request('POST', \sprintf('/api/v1/places/%s/reviews', $placeId), [], [], $csrfHeaders, json_encode([
            'rating' => 5,
            'body' => 'Ta bawialnia przerosła nasze oczekiwania. Super kawa i mnóstwo fajnych zabawek dla dzieci!',
            'visitedOn' => '2026-07-18',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $reviewData = json_decode($client->getResponse()->getContent(), true);
        $reviewId = $reviewData['id'];

        // 2. Fetch place reviews and check summary
        $client->request('GET', \sprintf('/api/v1/places/%s/reviews', $placeId));
        self::assertResponseIsSuccessful();
        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $res['summary']['totalReviews']);
        self::assertEquals(5.0, $res['summary']['averageRating']);
        self::assertCount(1, $res['items']);
        self::assertSame($reviewId, $res['items'][0]['id']);

        // 3. Prevent duplicate active review
        $client->request('POST', \sprintf('/api/v1/places/%s/reviews', $placeId), [], [], $csrfHeaders, json_encode([
            'rating' => 4,
            'body' => 'Inna opinia, która powinna zostać odrzucona przez unikalność.',
        ]));
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        // 4. Update own review
        $client->request('PATCH', \sprintf('/api/v1/me/reviews/%s', $reviewId), [], [], $csrfHeaders, json_encode([
            'rating' => 4,
            'body' => 'Edytowana opinia ze zmienioną oceną i długim poprawnym tekstem.',
            'version' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $updatedData = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(4, $updatedData['rating']);
        self::assertSame('2026-07-18', $updatedData['visitedOn']);
        self::assertSame(2, $updatedData['version']);

        // 5. Concurrency check (optimistic lock with old version)
        $client->request('PATCH', \sprintf('/api/v1/me/reviews/%s', $reviewId), [], [], $csrfHeaders, json_encode([
            'rating' => 5,
            'body' => 'Edytowana opinia ze zmienioną oceną i długim poprawnym tekstem.',
            'version' => 1, // sending obsolete version
        ]));
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        // 6. Delete own review (soft delete)
        $client->request('DELETE', \sprintf('/api/v1/me/reviews/%s', $reviewId), [], [], $csrfHeaders);
        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        // 7. Fetch reviews again - should be empty list and totalReviews should be 0
        $client->request('GET', \sprintf('/api/v1/places/%s/reviews', $placeId));
        self::assertResponseIsSuccessful();
        $resAfterDelete = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(0, $resAfterDelete['summary']['totalReviews']);
        self::assertCount(0, $resAfterDelete['items']);
    }

    public function testReviewPatchPreservesOmittedFieldsAndClearsExplicitNull(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $user = $this->createUser(\sprintf('patch-review-%d@example.com', random_int(10000, 99999)), 'Patch Review');
        $placeId = (string) $this->em->getConnection()->fetchOne("SELECT id FROM places WHERE status = 'published' LIMIT 1");
        $reviewId = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement('INSERT INTO reviews (id, place_id, author_id, rating, body, visited_on, status, created_at, updated_at, version) VALUES (:id, :place, :author, 4, :body, :visited, :status, :now, :now, 1)', ['id' => $reviewId, 'place' => $placeId, 'author' => $user->getId()->toRfc4122(), 'body' => 'Original review body long enough for validation.', 'visited' => '2026-07-18', 'status' => 'PUBLISHED', 'now' => $now]);
        $client->loginUser($user);
        $client->request('GET', '/api/v1/session');
        $headers = ['HTTP_X-CSRF-Token' => json_decode($client->getResponse()->getContent(), true)['csrfToken'], 'CONTENT_TYPE' => 'application/json'];

        $client->request('PATCH', "/api/v1/me/reviews/{$reviewId}", [], [], $headers, json_encode(['body' => 'Body-only update preserves every omitted review field.', 'version' => 1]));
        self::assertResponseIsSuccessful();
        $bodyOnly = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(4, $bodyOnly['rating']);
        self::assertSame('2026-07-18', $bodyOnly['visitedOn']);

        $client->request('PATCH', "/api/v1/me/reviews/{$reviewId}", [], [], $headers, json_encode(['rating' => 3, 'version' => 2]));
        self::assertResponseIsSuccessful();
        $ratingOnly = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($bodyOnly['body'], $ratingOnly['body']);
        self::assertSame('2026-07-18', $ratingOnly['visitedOn']);

        $client->request('PATCH', "/api/v1/me/reviews/{$reviewId}", [], [], $headers, json_encode(['visitedOn' => null, 'version' => 3]));
        self::assertResponseIsSuccessful();
        $cleared = json_decode($client->getResponse()->getContent(), true);
        self::assertNull($cleared['visitedOn']);
        self::assertSame(3, $cleared['rating']);
        self::assertSame($bodyOnly['body'], $cleared['body']);

        $client->request('PATCH', "/api/v1/me/reviews/{$reviewId}", [], [], $headers, json_encode(['version' => 4]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $client->request('PATCH', "/api/v1/me/reviews/{$reviewId}", [], [], $headers, json_encode(['rating' => null, 'version' => 4]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }
}
