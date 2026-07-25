<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class C5DSecondAuditClosureTest extends WebTestCase
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

    /** @return array<string, string> */
    private function getCsrfHeaders(string $csrfToken): array
    {
        return [
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @return array<string, string> */
    private function loginWithCsrf(KernelBrowser $client, User $user): array
    {
        $client->loginUser($user);
        $client->request('GET', '/api/v1/session');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $this->getCsrfHeaders($payload['csrfToken']);
    }

    private function createForumCategory(string $slug): Uuid
    {
        $categoryId = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, 1, true)',
            [
                'id' => $categoryId->toRfc4122(),
                'slug' => $slug,
                'name' => 'Audit Category',
                'description' => 'Audit coverage category.',
            ]
        );

        return $categoryId;
    }

    public function testInitialPostCannotBeDeletedDirectly(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $author = $this->createUser(\sprintf('initial-delete-%d@example.com', random_int(10000, 99999)), 'Initial Delete Author');
        $headers = $this->loginWithCsrf($client, $author);
        $categoryId = $this->createForumCategory('initial-delete-'.random_int(10000, 99999));

        $client->request('POST', \sprintf('/api/v1/forum/categories/%s/threads', $categoryId->toRfc4122()), [], [], $headers, json_encode([
            'title' => 'Initial post delete policy',
            'body' => 'This first post must be deleted via the thread endpoint.',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $client->request('DELETE', \sprintf('/api/v1/me/forum-posts/%s', $created['firstPost']['id']), [], [], $headers);

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('INITIAL_POST_DELETE_REQUIRES_THREAD_DELETE', (string) $client->getResponse()->getContent());
    }

    public function testClaimedReportStillBlocksDuplicateReport(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $reporter = $this->createUser(\sprintf('active-report-%d@example.com', random_int(10000, 99999)), 'Active Reporter');
        $author = $this->createUser(\sprintf('active-author-%d@example.com', random_int(10000, 99999)), 'Active Author');
        $moderator = $this->createUser(\sprintf('active-mod-%d@example.com', random_int(10000, 99999)), 'Active Moderator', ['ROLE_MODERATOR']);
        $placeId = (string) $this->em->getConnection()->fetchOne("SELECT id FROM places WHERE status = 'published' LIMIT 1");
        $reviewId = Uuid::v7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO reviews (id, place_id, author_id, rating, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, 5, :body, :status, :created_at, :updated_at)',
            [
                'id' => $reviewId->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author->getId()->toRfc4122(),
                'body' => 'This review is reported once and then claimed.',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $reporterHeaders = $this->loginWithCsrf($client, $reporter);
        $client->request('POST', '/api/v1/content-reports', [], [], $reporterHeaders, json_encode([
            'targetId' => $reviewId->toRfc4122(),
            'targetType' => 'REVIEW',
            'reason' => 'SPAM',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $reportId = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $moderatorHeaders = $this->loginWithCsrf($client, $moderator);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId), [], [], $moderatorHeaders);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->loginUser($reporter);
        $client->request('POST', '/api/v1/content-reports', [], [], $reporterHeaders, json_encode([
            'targetId' => $reviewId->toRfc4122(),
            'targetType' => 'REVIEW',
            'reason' => 'SPAM',
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('REPORT_ALREADY_EXISTS', (string) $client->getResponse()->getContent());
    }

    public function testInitialPostModerationCaseAllowsOnlyReportClosureActions(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $author = $this->createUser(\sprintf('initial-case-author-%d@example.com', random_int(10000, 99999)), 'Initial Case Author');
        $reporter = $this->createUser(\sprintf('initial-case-reporter-%d@example.com', random_int(10000, 99999)), 'Initial Case Reporter');
        $moderator = $this->createUser(\sprintf('initial-case-mod-%d@example.com', random_int(10000, 99999)), 'Initial Case Moderator', ['ROLE_MODERATOR']);
        $categoryId = $this->createForumCategory('initial-case-'.random_int(10000, 99999));

        $authorHeaders = $this->loginWithCsrf($client, $author);
        $client->request('POST', \sprintf('/api/v1/forum/categories/%s/threads', $categoryId->toRfc4122()), [], [], $authorHeaders, json_encode([
            'title' => 'Initial post moderation case',
            'body' => 'This post is the thread body.',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $reporterHeaders = $this->loginWithCsrf($client, $reporter);
        $client->request('POST', '/api/v1/content-reports', [], [], $reporterHeaders, json_encode([
            'targetId' => $created['firstPost']['id'],
            'targetType' => 'FORUM_POST',
            'reason' => 'SPAM',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $reportId = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $moderatorHeaders = $this->loginWithCsrf($client, $moderator);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId), [], [], $moderatorHeaders);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', \sprintf('/api/v1/moderation/case/%s', $reportId));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $case = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['DISMISS_REPORT', 'RESOLVE_REPORT'], $case['allowedActions']);
        self::assertTrue($case['targetPreview']['isInitial']);

        $moderatorHeaders['HTTP_IDEMPOTENCY_KEY'] = Uuid::v7()->toRfc4122();
        $client->request('POST', '/api/v1/moderation/action', [], [], $moderatorHeaders, json_encode([
            'reportId' => $reportId,
            'action' => 'HIDE',
            'reason' => 'This must be routed through thread moderation.',
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('INITIAL_POST_REQUIRES_THREAD_TARGET', (string) $client->getResponse()->getContent());
    }

    public function testIdempotencyKeyReuseWithChangedPayloadReturnsConflict(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $author = $this->createUser(\sprintf('idem-author-%d@example.com', random_int(10000, 99999)), 'Idem Author');
        $reporter = $this->createUser(\sprintf('idem-reporter-%d@example.com', random_int(10000, 99999)), 'Idem Reporter');
        $moderator = $this->createUser(\sprintf('idem-mod-%d@example.com', random_int(10000, 99999)), 'Idem Moderator', ['ROLE_MODERATOR']);
        $placeId = (string) $this->em->getConnection()->fetchOne("SELECT id FROM places WHERE status = 'published' LIMIT 1");
        $reviewId = Uuid::v7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO reviews (id, place_id, author_id, rating, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, 4, :body, :status, :created_at, :updated_at)',
            [
                'id' => $reviewId->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author->getId()->toRfc4122(),
                'body' => 'Idempotency reuse should be rejected on changed payload.',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $reporterHeaders = $this->loginWithCsrf($client, $reporter);
        $client->request('POST', '/api/v1/content-reports', [], [], $reporterHeaders, json_encode([
            'targetId' => $reviewId->toRfc4122(),
            'targetType' => 'REVIEW',
            'reason' => 'OTHER',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $reportId = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $moderatorHeaders = $this->loginWithCsrf($client, $moderator);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId), [], [], $moderatorHeaders);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $idempotencyKey = Uuid::v7()->toRfc4122();
        $moderatorHeaders['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        $client->request('POST', '/api/v1/moderation/action', [], [], $moderatorHeaders, json_encode([
            'reportId' => $reportId,
            'action' => 'RESOLVE_REPORT',
            'reason' => 'Original moderation outcome.',
        ]));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/moderation/action', [], [], $moderatorHeaders, json_encode([
            'reportId' => $reportId,
            'action' => 'RESOLVE_REPORT',
            'reason' => 'Changed moderation reason.',
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('IDEMPOTENCY_KEY_REUSE', (string) $client->getResponse()->getContent());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM moderation_actions WHERE report_id = :report_id', ['report_id' => $reportId]));
    }

    public function testFeedDoesNotDuplicateThreadCreationWithInitialPostActivity(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $author = $this->createUser(\sprintf('feed-author-%d@example.com', random_int(10000, 99999)), 'Feed Author');
        $replier = $this->createUser(\sprintf('feed-replier-%d@example.com', random_int(10000, 99999)), 'Feed Replier');
        $categoryId = $this->createForumCategory('feed-dedupe-'.random_int(10000, 99999));

        $authorHeaders = $this->loginWithCsrf($client, $author);
        $client->request('POST', \sprintf('/api/v1/forum/categories/%s/threads', $categoryId->toRfc4122()), [], [], $authorHeaders, json_encode([
            'title' => 'Feed dedupe thread',
            'body' => 'Initial forum body should not appear as a separate feed post.',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $client->request('GET', '/api/v1/community/feed');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $feed = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['items'];
        $threadItems = array_values(array_filter($feed, static fn (array $item): bool => 'forum_thread' === $item['type'] && $item['id'] === $created['id']));
        $initialPostItems = array_values(array_filter($feed, static fn (array $item): bool => $item['id'] === $created['firstPost']['id']));
        self::assertCount(1, $threadItems);
        self::assertCount(0, $initialPostItems);

        $replyHeaders = $this->loginWithCsrf($client, $replier);
        $client->request('POST', \sprintf('/api/v1/forum/threads/%s/posts', $created['id']), [], [], $replyHeaders, json_encode([
            'body' => 'Follow-up reply should appear in the feed.',
            'replyToPostId' => $created['firstPost']['id'],
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $reply = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $client->request('GET', '/api/v1/community/feed?type=forum_post');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $postFeed = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['items'];
        $replyItems = array_values(array_filter($postFeed, static fn (array $item): bool => 'forum_post' === $item['type'] && $item['id'] === $reply['id']));
        $initialItems = array_values(array_filter($postFeed, static fn (array $item): bool => $item['id'] === $created['firstPost']['id']));
        self::assertCount(1, $replyItems);
        self::assertCount(0, $initialItems);
    }

    public function testHiddenInitialPostSuppressesPublicThreadReadPaths(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $author = $this->createUser(\sprintf('hidden-initial-%d@example.com', random_int(10000, 99999)), 'Hidden Initial Author');
        $categorySlug = 'hidden-initial-'.random_int(10000, 99999);
        $categoryId = $this->createForumCategory($categorySlug);
        $threadId = Uuid::v7();
        $postId = Uuid::v7();
        $title = 'Legacy hidden initial thread';
        $body = 'This hidden initial body must never leak through public queries.';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at, version) VALUES (:id, :category_id, :author_id, :title, :status, :created_at, :updated_at, :last_activity_at, 1)',
            [
                'id' => $threadId->toRfc4122(),
                'category_id' => $categoryId->toRfc4122(),
                'author_id' => $author->getId()->toRfc4122(),
                'title' => $title,
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
                'last_activity_at' => $now,
            ]
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_posts (id, thread_id, author_id, body, status, created_at, updated_at, is_initial, version) VALUES (:id, :thread_id, :author_id, :body, :status, :created_at, :updated_at, :is_initial, 1)',
            [
                'id' => $postId->toRfc4122(),
                'thread_id' => $threadId->toRfc4122(),
                'author_id' => $author->getId()->toRfc4122(),
                'body' => $body,
                'status' => 'HIDDEN',
                'created_at' => $now,
                'updated_at' => $now,
                'is_initial' => 'true',
            ]
        );

        $client->request('GET', \sprintf('/api/v1/forum/threads/%s', $threadId->toRfc4122()));
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());

        $client->request('GET', \sprintf('/api/v1/forum/categories/%s/threads', $categorySlug));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($title, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($body, (string) $client->getResponse()->getContent());

        $client->request('GET', '/api/v1/community/feed');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($title, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($body, (string) $client->getResponse()->getContent());
    }

    public function testPublicCommentsExcludeHiddenEntries(): void
    {
        $client = self::createClient();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $author = $this->createUser(\sprintf('comment-author-%d@example.com', random_int(10000, 99999)), 'Comment Author');
        $placeId = (string) $this->em->getConnection()->fetchOne("SELECT id FROM places WHERE status = 'published' LIMIT 1");
        $visibleBody = 'Visible discussion comment';
        $hiddenBody = 'Hidden discussion comment';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->em->getConnection()->executeStatement(
            'INSERT INTO place_comments (id, place_id, author_id, body, status, created_at, updated_at, version) VALUES (:id, :place_id, :author_id, :body, :status, :created_at, :updated_at, 1)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author->getId()->toRfc4122(),
                'body' => $visibleBody,
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO place_comments (id, place_id, author_id, body, status, created_at, updated_at, version) VALUES (:id, :place_id, :author_id, :body, :status, :created_at, :updated_at, 1)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author->getId()->toRfc4122(),
                'body' => $hiddenBody,
                'status' => 'HIDDEN',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $client->request('GET', \sprintf('/api/v1/places/%s/comments', $placeId));

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($visibleBody, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($hiddenBody, (string) $client->getResponse()->getContent());
    }
}
