<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class C5DCursorSchemaTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private function createUser(string $email, string $displayName, array $roles = ['ROLE_USER']): User
    {
        $user = new User(
            email: new \App\Identity\Domain\ValueObject\EmailAddress($email),
            displayName: $displayName,
            createdAt: new \DateTimeImmutable(),
            roles: $roles,
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function assertInvalidCursor(KernelBrowser $client, string $path, array $payload): void
    {
        $client->request('GET', $path.rawurlencode(base64_encode(json_encode($payload, \JSON_THROW_ON_ERROR))));
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode(), $path);
        self::assertStringContainsString('INVALID_CURSOR', (string) $client->getResponse()->getContent(), $path);
    }

    public function testCommunityCursorSchemasRejectMalformedPayloadsAndAcceptValidRoundTrips(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $now = '2026-07-25 08:00:00';
        $author = $this->createUser(\sprintf('cursor-author-%d@example.com', random_int(10000, 99999)), 'Cursor Author');
        $moderator = $this->createUser(\sprintf('cursor-mod-%d@example.com', random_int(10000, 99999)), 'Cursor Moderator', ['ROLE_MODERATOR']);

        $categoryId = Uuid::v7();
        $categorySlug = 'cursor-schema-'.random_int(10000, 99999);
        $threadId = Uuid::v7();
        $threadPostId = Uuid::v7();
        $commentId = Uuid::v7();
        $reportId = Uuid::v7();
        $placeId = (string) $this->em->getConnection()->fetchOne("SELECT id FROM places WHERE status = 'published' LIMIT 1");

        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, 1, true)',
            [
                'id' => $categoryId->toRfc4122(),
                'slug' => $categorySlug,
                'name' => 'Cursor Category',
                'description' => 'Cursor coverage category',
            ]
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at, version) VALUES (:id, :category_id, :author_id, :title, :status, :created_at, :updated_at, :last_activity_at, 1)',
            [
                'id' => $threadId->toRfc4122(),
                'category_id' => $categoryId->toRfc4122(),
                'author_id' => $author->getId()->toRfc4122(),
                'title' => 'Cursor schema thread',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
                'last_activity_at' => $now,
            ]
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_posts (id, thread_id, author_id, body, status, created_at, updated_at, is_initial, version) VALUES (:id, :thread_id, :author_id, :body, :status, :created_at, :updated_at, :is_initial, 1)',
            [
                'id' => $threadPostId->toRfc4122(),
                'thread_id' => $threadId->toRfc4122(),
                'author_id' => $author->getId()->toRfc4122(),
                'body' => 'Cursor schema thread body',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
                'is_initial' => 'true',
            ]
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO place_comments (id, place_id, author_id, body, status, created_at, updated_at, version) VALUES (:id, :place_id, :author_id, :body, :status, :created_at, :updated_at, 1)',
            [
                'id' => $commentId->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author->getId()->toRfc4122(),
                'body' => 'Cursor schema visible comment',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, status, created_at) VALUES (:id, :reporter_id, :target_type, :target_id, :reason, :status, :created_at)',
            [
                'id' => $reportId->toRfc4122(),
                'reporter_id' => $author->getId()->toRfc4122(),
                'target_type' => 'FORUM_THREAD',
                'target_id' => $threadId->toRfc4122(),
                'reason' => 'OTHER',
                'status' => 'OPEN',
                'created_at' => $now,
            ]
        );

        $client->request('GET', '/api/v1/forum/categories/'.$categorySlug.'/threads?cursor='.rawurlencode(base64_encode(json_encode([
            'id' => $threadId->toRfc4122(),
            'pinnedAt' => null,
            'lastActivityAt' => $now,
            'categorySlug' => $categorySlug,
        ], \JSON_THROW_ON_ERROR))));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/forum/threads/'.$threadId->toRfc4122().'/posts?cursor='.rawurlencode(base64_encode(json_encode([
            'id' => $threadPostId->toRfc4122(),
            'createdAt' => $now,
            'threadId' => $threadId->toRfc4122(),
        ], \JSON_THROW_ON_ERROR))));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/places/'.$placeId.'/comments?cursor='.rawurlencode(base64_encode(json_encode([
            'createdAt' => $now,
            'id' => $commentId->toRfc4122(),
            'placeId' => $placeId,
        ], \JSON_THROW_ON_ERROR))));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/community/feed?cursor='.rawurlencode(base64_encode(json_encode([
            'id' => $threadId->toRfc4122(),
            'activityAt' => $now,
            'type' => null,
            'cityId' => null,
            'categoryId' => null,
        ], \JSON_THROW_ON_ERROR))));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->loginUser($moderator);
        $client->request('GET', '/api/v1/moderation/queue?status=OPEN&cursor='.rawurlencode(base64_encode(json_encode([
            'priority' => 1,
            'createdAt' => $now,
            'id' => $reportId->toRfc4122(),
            'statusFilter' => 'OPEN',
        ], \JSON_THROW_ON_ERROR))));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $this->assertInvalidCursor($client, '/api/v1/forum/categories/'.$categorySlug.'/threads?cursor=', [
            'id' => null,
            'pinnedAt' => null,
            'lastActivityAt' => $now,
            'categorySlug' => $categorySlug,
        ]);
        $this->assertInvalidCursor($client, '/api/v1/forum/categories/'.$categorySlug.'/threads?cursor=', [
            'id' => $threadId->toRfc4122(),
            'pinnedAt' => null,
            'lastActivityAt' => null,
            'categorySlug' => $categorySlug,
        ]);
        $this->assertInvalidCursor($client, '/api/v1/forum/categories/'.$categorySlug.'/threads?cursor=', [
            'id' => 123,
            'pinnedAt' => null,
            'lastActivityAt' => $now,
            'categorySlug' => $categorySlug,
        ]);
        $this->assertInvalidCursor($client, '/api/v1/forum/categories/'.$categorySlug.'/threads?cursor=', [
            'id' => $threadId->toRfc4122(),
            'pinnedAt' => null,
            'lastActivityAt' => $now,
            'categorySlug' => 'other-category',
        ]);
        $this->assertInvalidCursor($client, '/api/v1/forum/threads/'.$threadId->toRfc4122().'/posts?cursor=', [
            'id' => 'not-a-uuid',
            'createdAt' => $now,
            'threadId' => $threadId->toRfc4122(),
        ]);
        $this->assertInvalidCursor($client, '/api/v1/forum/threads/'.$threadId->toRfc4122().'/posts?cursor=', [
            'id' => $threadPostId->toRfc4122(),
            'createdAt' => 'not-a-date',
            'threadId' => $threadId->toRfc4122(),
        ]);
        $this->assertInvalidCursor($client, '/api/v1/places/'.$placeId.'/comments?cursor=', [
            'createdAt' => $now,
            'placeId' => $placeId,
        ]);
        $this->assertInvalidCursor($client, '/api/v1/places/'.$placeId.'/comments?cursor=', [
            'createdAt' => $now,
            'id' => $commentId->toRfc4122(),
            'placeId' => $placeId,
            'extra' => 'unexpected',
        ]);
        $this->assertInvalidCursor($client, '/api/v1/community/feed?cursor=', [
            'id' => $threadId->toRfc4122(),
            'activityAt' => null,
            'type' => null,
            'cityId' => null,
            'categoryId' => null,
        ]);
        $this->assertInvalidCursor($client, '/api/v1/community/feed?cursor=', [
            'id' => $threadId->toRfc4122(),
            'activityAt' => $now,
            'type' => 'review',
            'cityId' => null,
            'categoryId' => null,
        ]);
        $this->assertInvalidCursor($client, '/api/v1/moderation/queue?status=OPEN&cursor=', [
            'priority' => 'one',
            'createdAt' => $now,
            'id' => $reportId->toRfc4122(),
            'statusFilter' => 'OPEN',
        ]);
        $this->assertInvalidCursor($client, '/api/v1/moderation/queue?status=OPEN&cursor=', [
            'priority' => 1,
            'createdAt' => $now,
            'id' => $reportId->toRfc4122(),
            'statusFilter' => 'RESOLVED',
        ]);
    }
}
