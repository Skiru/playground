<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Community\Domain\Review\Review;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class C5DForumModerationRegressionTest extends WebTestCase
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

    private function getCsrfHeaders(string $csrfToken): array
    {
        return [
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testFeedVisibilityContainingSecrets(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $alice = $this->createUser(\sprintf('alice-c5d-feed-%d@example.com', random_int(10000, 99999)), 'Alice');
        $client->loginUser($alice);

        // Fetch CSRF token
        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = $this->getCsrfHeaders($csrfToken);

        // Secrets markers for test verification
        $secretThreadTitle = 'SECRET_MARKER_HIDDEN_THREAD_'.random_int(10000, 99999);
        $secretPostText = 'SECRET_MARKER_POST_IN_INACTIVE_CAT_'.random_int(10000, 99999);
        $secretReviewText = 'SECRET_MARKER_REVIEW_UNPUBLISHED_PLACE_'.random_int(10000, 99999);
        $secretCommentText = 'SECRET_MARKER_COMMENT_UNPUBLISHED_PLACE_'.random_int(10000, 99999);

        // 1. Inactive category and a published thread/post inside it
        $inactiveCategoryUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, :display_order, :active)',
            [
                'id' => $inactiveCategoryUuid->toRfc4122(),
                'slug' => 'inactive-cat-'.random_int(1000, 9999),
                'name' => 'Inactive Cat',
                'description' => 'Inactive',
                'display_order' => 10,
                'active' => 0, // Inactive!
            ]
        );

        $threadUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at) VALUES (:id, :category_id, :author_id, :title, :status, :created_at, :updated_at, :last_activity_at)',
            [
                'id' => $threadUuid->toRfc4122(),
                'category_id' => $inactiveCategoryUuid->toRfc4122(),
                'author_id' => $alice->getId()->toRfc4122(),
                'title' => $secretThreadTitle,
                'status' => 'PUBLISHED',
                'created_at' => '2026-07-20 12:00:00',
                'updated_at' => '2026-07-20 12:00:00',
                'last_activity_at' => '2026-07-20 12:00:00',
            ]
        );

        $postUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_posts (id, thread_id, author_id, body, status, created_at, updated_at) VALUES (:id, :thread_id, :author_id, :body, :status, :created_at, :updated_at)',
            [
                'id' => $postUuid->toRfc4122(),
                'thread_id' => $threadUuid->toRfc4122(),
                'author_id' => $alice->getId()->toRfc4122(),
                'body' => $secretPostText,
                'status' => 'PUBLISHED',
                'created_at' => '2026-07-20 12:01:00',
                'updated_at' => '2026-07-20 12:01:00',
            ]
        );

        // 2. Unpublished place and review/comment inside it
        $unpublishedPlaceUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            "INSERT INTO places (
                id, city_id, primary_category_id, slug, name, normalized_name, 
                short_description, description, status, verification_status, 
                address_line1, postal_code, country_code, location, latitude, longitude, 
                timezone, indoor, outdoor, free_entry, version, opening_hours_mode, created_at, updated_at
            ) VALUES (
                :id, :city_id, :primary_category_id, :slug, :name, :normalized_name, 
                :short_description, :description, :status, :verification_status, 
                :address_line1, :postal_code, :country_code, ST_GeomFromText('POINT(21.0122 52.2297)', 4326), :latitude, :longitude, 
                :timezone, :indoor, :outdoor, :free_entry, :version, :opening_hours_mode, :created_at, :updated_at
            )",
            [
                'id' => $unpublishedPlaceUuid->toRfc4122(),
                'city_id' => '00000000-0000-7000-8000-000000000100', // Valid City ID from DB
                'primary_category_id' => '00000000-0000-7000-8000-000000000201', // Valid Category ID from DB
                'slug' => 'secret-playroom-'.random_int(100, 999),
                'name' => 'Secret Playroom',
                'normalized_name' => 'secret playroom',
                'short_description' => 'Short draft',
                'description' => 'Draft description',
                'status' => 'draft', // NOT published!
                'verification_status' => 'admin_verified',
                'address_line1' => 'Street 1',
                'postal_code' => '00-000',
                'country_code' => 'PL',
                'latitude' => 52.2297,
                'longitude' => 21.0122,
                'timezone' => 'Europe/Warsaw',
                'indoor' => 1,
                'outdoor' => 0,
                'free_entry' => 1,
                'version' => 1,
                'opening_hours_mode' => 'scheduled',
                'created_at' => '2026-07-20 12:00:00',
                'updated_at' => '2026-07-20 12:00:00',
            ]
        );

        $reviewUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO reviews (id, place_id, author_id, rating, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, :rating, :body, :status, :created_at, :updated_at)',
            [
                'id' => $reviewUuid->toRfc4122(),
                'place_id' => $unpublishedPlaceUuid->toRfc4122(),
                'author_id' => $alice->getId()->toRfc4122(),
                'rating' => 5,
                'body' => $secretReviewText,
                'status' => 'PUBLISHED',
                'created_at' => '2026-07-20 12:02:00',
                'updated_at' => '2026-07-20 12:02:00',
            ]
        );

        $commentUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO place_comments (id, place_id, author_id, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, :body, :status, :created_at, :updated_at)',
            [
                'id' => $commentUuid->toRfc4122(),
                'place_id' => $unpublishedPlaceUuid->toRfc4122(),
                'author_id' => $alice->getId()->toRfc4122(),
                'body' => $secretCommentText,
                'status' => 'PUBLISHED',
                'created_at' => '2026-07-20 12:03:00',
                'updated_at' => '2026-07-20 12:03:00',
            ]
        );

        // Fetch feed and verify no secrets leak
        $client->request('GET', '/api/v1/community/feed');
        self::assertResponseIsSuccessful();
        $feedContent = $client->getResponse()->getContent();

        self::assertStringNotContainsString($secretThreadTitle, $feedContent);
        self::assertStringNotContainsString($secretPostText, $feedContent);
        self::assertStringNotContainsString($secretReviewText, $feedContent);
        self::assertStringNotContainsString($secretCommentText, $feedContent);
    }

    public function testInaccessibleTargetReporting(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $bob = $this->createUser(\sprintf('bob-c5d-report-%d@example.com', random_int(10000, 99999)), 'Bob');
        $client->loginUser($bob);

        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = $this->getCsrfHeaders($csrfToken);

        // Attempt reporting a non-existent review
        $fakeUuid = Uuid::v7()->toRfc4122();
        $client->request('POST', '/api/v1/content-reports', [], [], $csrfHeaders, json_encode([
            'targetId' => $fakeUuid,
            'targetType' => 'REVIEW',
            'reason' => 'SPAM',
            'details' => 'Reporting non-existent target should give 404 non-disclosure.',
        ]));

        $content = $client->getResponse()->getContent();

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $res = json_decode($content, true);
        self::assertSame('MISSING_PUBLIC_RESOURCE', $res['code']);
    }

    public function testReplyToNonPublicPost(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $alice = $this->createUser(\sprintf('alice-c5d-reply-%d@example.com', random_int(10000, 99999)), 'Alice');
        $client->loginUser($alice);

        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = $this->getCsrfHeaders($csrfToken);

        // Create thread and a hidden post
        $categoryUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, :display_order, :active)',
            [
                'id' => $categoryUuid->toRfc4122(),
                'slug' => 'test-cat-reply-'.random_int(1000, 9999),
                'name' => 'Active Category',
                'description' => 'Active',
                'display_order' => 1,
                'active' => 1,
            ]
        );

        $threadUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at) VALUES (:id, :category_id, :author_id, :title, :status, :created_at, :updated_at, :last_activity_at)',
            [
                'id' => $threadUuid->toRfc4122(),
                'category_id' => $categoryUuid->toRfc4122(),
                'author_id' => $alice->getId()->toRfc4122(),
                'title' => 'Reply target thread',
                'status' => 'PUBLISHED',
                'created_at' => '2026-07-20 12:00:00',
                'updated_at' => '2026-07-20 12:00:00',
                'last_activity_at' => '2026-07-20 12:00:00',
            ]
        );

        $hiddenPostUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_posts (id, thread_id, author_id, body, status, created_at, updated_at) VALUES (:id, :thread_id, :author_id, :body, :status, :created_at, :updated_at)',
            [
                'id' => $hiddenPostUuid->toRfc4122(),
                'thread_id' => $threadUuid->toRfc4122(),
                'author_id' => $alice->getId()->toRfc4122(),
                'body' => 'Hidden post content',
                'status' => 'HIDDEN', // Not public!
                'created_at' => '2026-07-20 12:01:00',
                'updated_at' => '2026-07-20 12:01:00',
            ]
        );

        // Try replying to the hidden post
        $client->request('POST', \sprintf('/api/v1/forum/threads/%s/posts', $threadUuid->toRfc4122()), [], [], $csrfHeaders, json_encode([
            'body' => 'This is a reply to a hidden post.',
            'replyToPostId' => $hiddenPostUuid->toRfc4122(),
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_PARENT_STATUS', $res['code']);
    }

    public function testDuplicateReportAndRace(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $alice = $this->createUser(\sprintf('alice-c5d-dupe-%d@example.com', random_int(10000, 99999)), 'Alice');
        $client->loginUser($alice);

        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = $this->getCsrfHeaders($csrfToken);

        // Fetch first published place
        $placeIdRow = $this->em->getConnection()->fetchAssociative('SELECT id FROM places WHERE status = \'published\' LIMIT 1');
        self::assertNotFalse($placeIdRow);
        $placeId = $placeIdRow['id'];

        // Create a review
        $reviewUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO reviews (id, place_id, author_id, rating, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, :rating, :body, :status, :created_at, :updated_at)',
            [
                'id' => $reviewUuid->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $alice->getId()->toRfc4122(),
                'rating' => 4,
                'body' => 'Great place to have fun with children!',
                'status' => 'PUBLISHED',
                'created_at' => '2026-07-20 12:00:00',
                'updated_at' => '2026-07-20 12:00:00',
            ]
        );

        // Submit first report
        $client->request('POST', '/api/v1/content-reports', [], [], $csrfHeaders, json_encode([
            'targetId' => $reviewUuid->toRfc4122(),
            'targetType' => 'REVIEW',
            'reason' => 'SPAM',
            'details' => 'Spamming',
        ]));

        $content1 = $client->getResponse()->getContent();
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        // Submit duplicate report immediately and check 409
        $client->request('POST', '/api/v1/content-reports', [], [], $csrfHeaders, json_encode([
            'targetId' => $reviewUuid->toRfc4122(),
            'targetType' => 'REVIEW',
            'reason' => 'SPAM',
            'details' => 'Spamming again',
        ]));

        $content2 = $client->getResponse()->getContent();
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        $res = json_decode($content2, true);
        self::assertSame('REPORT_ALREADY_EXISTS', $res['code']);
    }

    public function testMixedTargetModerationQueueMapping(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $rand = random_int(10000, 99999);
        $moderator = $this->createUser(\sprintf('mod-queue-test-%d@example.com', $rand), 'ModQueue', ['ROLE_MODERATOR']);

        $author1 = $this->createUser(\sprintf('auth1-%d@example.com', $rand), 'Author One');
        $author2 = $this->createUser(\sprintf('auth2-%d@example.com', $rand), 'Author Two');
        $author3 = $this->createUser(\sprintf('auth3-%d@example.com', $rand), 'Author Three');
        $author4 = $this->createUser(\sprintf('auth4-%d@example.com', $rand), 'Author Four');

        $reporter1 = $this->createUser(\sprintf('rep1-%d@example.com', $rand), 'Reporter One');
        $reporter2 = $this->createUser(\sprintf('rep2-%d@example.com', $rand), 'Reporter Two');
        $reporter3 = $this->createUser(\sprintf('rep3-%d@example.com', $rand), 'Reporter Three');
        $reporter4 = $this->createUser(\sprintf('rep4-%d@example.com', $rand), 'Reporter Four');

        // Fetch place
        $placeRow = $this->em->getConnection()->fetchAssociative('SELECT id, slug FROM places LIMIT 1');
        self::assertNotFalse($placeRow);
        $placeId = $placeRow['id'];
        $placeSlug = $placeRow['slug'];

        // Create category
        $categoryUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, :display_order, :active)',
            [
                'id' => $categoryUuid->toRfc4122(),
                'slug' => 'queue-cat-'.random_int(1000, 9999),
                'name' => 'Queue Category',
                'description' => 'Desc',
                'display_order' => 1,
                'active' => 1,
            ]
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // 1. REVIEW
        $reviewUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO reviews (id, place_id, author_id, rating, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, :rating, :body, :status, :created_at, :updated_at)',
            [
                'id' => $reviewUuid->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author1->getId()->toRfc4122(),
                'rating' => 5,
                'body' => 'REVIEW_EVIDENCE_BODY',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 2. PLACE_COMMENT
        $commentUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO place_comments (id, place_id, author_id, body, status, created_at, updated_at) VALUES (:id, :place_id, :author_id, :body, :status, :created_at, :updated_at)',
            [
                'id' => $commentUuid->toRfc4122(),
                'place_id' => $placeId,
                'author_id' => $author2->getId()->toRfc4122(),
                'body' => 'PLACE_COMMENT_EVIDENCE_BODY',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 3. FORUM_THREAD
        $threadUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at) VALUES (:id, :category_id, :author_id, :title, :status, :created_at, :updated_at, :last_activity_at)',
            [
                'id' => $threadUuid->toRfc4122(),
                'category_id' => $categoryUuid->toRfc4122(),
                'author_id' => $author3->getId()->toRfc4122(),
                'title' => 'FORUM_THREAD_EVIDENCE_TITLE',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
                'last_activity_at' => $now,
            ]
        );

        // 4. FORUM_POST
        $postUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_posts (id, thread_id, author_id, body, status, created_at, updated_at) VALUES (:id, :thread_id, :author_id, :body, :status, :created_at, :updated_at)',
            [
                'id' => $postUuid->toRfc4122(),
                'thread_id' => $threadUuid->toRfc4122(),
                'author_id' => $author4->getId()->toRfc4122(),
                'body' => 'FORUM_POST_EVIDENCE_BODY',
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // Now create reports
        $reportReviewUuid = Uuid::v7();
        $reportCommentUuid = Uuid::v7();
        $reportThreadUuid = Uuid::v7();
        $reportPostUuid = Uuid::v7();

        $this->em->getConnection()->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at) VALUES '.
            '(:id1, :rep1, \'REVIEW\', :tid1, \'SPAM\', \'Spam review\', \'OPEN\', :created_at), '.
            '(:id2, :rep2, \'PLACE_COMMENT\', :tid2, \'HARASSMENT\', \'Harassment comment\', \'OPEN\', :created_at), '.
            '(:id3, :rep3, \'FORUM_THREAD\', :tid3, \'OFF_TOPIC\', \'Offtopic thread\', \'OPEN\', :created_at), '.
            '(:id4, :rep4, \'FORUM_POST\', :tid4, \'SPAM\', \'Spam post\', \'OPEN\', :created_at)',
            [
                'id1' => $reportReviewUuid->toRfc4122(),
                'rep1' => $reporter1->getId()->toRfc4122(),
                'tid1' => $reviewUuid->toRfc4122(),
                'id2' => $reportCommentUuid->toRfc4122(),
                'rep2' => $reporter2->getId()->toRfc4122(),
                'tid2' => $commentUuid->toRfc4122(),
                'id3' => $reportThreadUuid->toRfc4122(),
                'rep3' => $reporter3->getId()->toRfc4122(),
                'tid3' => $threadUuid->toRfc4122(),
                'id4' => $reportPostUuid->toRfc4122(),
                'rep4' => $reporter4->getId()->toRfc4122(),
                'tid4' => $postUuid->toRfc4122(),
                'created_at' => $now,
            ]
        );

        // Fetch queue as moderator
        $client->loginUser($moderator);
        $client->request('GET', '/api/v1/moderation/queue');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $items = $data['items'] ?? [];
        self::assertNotEmpty($items);

        // Find and map items by target ID
        $itemsByTarget = [];
        foreach ($items as $item) {
            $itemsByTarget[$item['targetId']] = $item;
        }

        // Assert all 4 exist in the returned page
        self::assertArrayHasKey($reviewUuid->toRfc4122(), $itemsByTarget);
        self::assertArrayHasKey($commentUuid->toRfc4122(), $itemsByTarget);
        self::assertArrayHasKey($threadUuid->toRfc4122(), $itemsByTarget);
        self::assertArrayHasKey($postUuid->toRfc4122(), $itemsByTarget);

        // 1. Assert REVIEW report
        $revItem = $itemsByTarget[$reviewUuid->toRfc4122()];
        self::assertSame('REVIEW', $revItem['targetType']);
        self::assertSame('REVIEW_EVIDENCE_BODY', $revItem['evidence']);
        self::assertSame($author1->getDisplayName(), $revItem['author']['displayName']);
        self::assertSame("/miejsca/{$placeSlug}", $revItem['publicLink']);

        // 2. Assert PLACE_COMMENT report
        $commItem = $itemsByTarget[$commentUuid->toRfc4122()];
        self::assertSame('PLACE_COMMENT', $commItem['targetType']);
        self::assertSame('PLACE_COMMENT_EVIDENCE_BODY', $commItem['evidence']);
        self::assertSame($author2->getDisplayName(), $commItem['author']['displayName']);
        self::assertSame("/miejsca/{$placeSlug}", $commItem['publicLink']);

        // 3. Assert FORUM_THREAD report
        $thrItem = $itemsByTarget[$threadUuid->toRfc4122()];
        self::assertSame('FORUM_THREAD', $thrItem['targetType']);
        self::assertSame('FORUM_THREAD_EVIDENCE_TITLE', $thrItem['evidence']);
        self::assertSame($author3->getDisplayName(), $thrItem['author']['displayName']);
        self::assertSame("/forum/watek/{$threadUuid->toRfc4122()}", $thrItem['publicLink']);

        // 4. Assert FORUM_POST report
        $postItem = $itemsByTarget[$postUuid->toRfc4122()];
        self::assertSame('FORUM_POST', $postItem['targetType']);
        self::assertSame('FORUM_POST_EVIDENCE_BODY', $postItem['evidence']);
        self::assertSame($author4->getDisplayName(), $postItem['author']['displayName']);
        self::assertSame("/forum/watek/{$threadUuid->toRfc4122()}", $postItem['publicLink']);

        // Confirm there is no cross-target contamination in any of the evidences
        foreach ($itemsByTarget as $tid => $item) {
            if ($tid === $reviewUuid->toRfc4122()) {
                self::assertStringNotContainsString('PLACE_COMMENT_EVIDENCE_BODY', $item['evidence']);
            }
            if ($tid === $commentUuid->toRfc4122()) {
                self::assertStringNotContainsString('REVIEW_EVIDENCE_BODY', $item['evidence']);
            }
        }
    }

    public function testModeratorQueueCursorPagination(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $rand = random_int(10000, 99999);
        $moderator = $this->createUser(\sprintf('mod-cursor-%d@example.com', $rand), 'ModCursor', ['ROLE_MODERATOR']);
        $reporter = $this->createUser(\sprintf('rep-cursor-%d@example.com', $rand), 'RepCursor');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->em->getConnection()->beginTransaction();
        try {
            for ($i = 1; $i <= 125; ++$i) {
                $targetId = Uuid::v7();
                $reportId = Uuid::v7();
                $createdAt = (new \DateTimeImmutable(\sprintf('-%d seconds', $i)))->format('Y-m-d H:i:s');

                $this->em->getConnection()->executeStatement(
                    'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at) VALUES '.
                    '(:id, :reporter_id, \'REVIEW\', :target_id, \'SPAM\', :details, \'OPEN\', :created_at)',
                    [
                        'id' => $reportId->toRfc4122(),
                        'reporter_id' => $reporter->getId()->toRfc4122(),
                        'target_id' => $targetId->toRfc4122(),
                        'details' => "Report number {$i}",
                        'created_at' => $createdAt,
                    ]
                );
            }
            $this->em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            throw $e;
        }

        // Fetch first page of 50 reports
        $client->loginUser($moderator);
        $client->request('GET', '/api/v1/moderation/queue?status=OPEN&limit=50');
        self::assertResponseIsSuccessful();
        $page1 = json_decode($client->getResponse()->getContent(), true);

        self::assertCount(50, $page1['items']);
        self::assertTrue($page1['pagination']['hasNextPage']);
        self::assertNotNull($page1['pagination']['nextCursor']);

        $page1Ids = array_column($page1['items'], 'id');

        // Fetch second page of 50 reports using cursor
        $cursor1 = $page1['pagination']['nextCursor'];
        $client->request('GET', "/api/v1/moderation/queue?status=OPEN&limit=50&cursor={$cursor1}");
        self::assertResponseIsSuccessful();
        $page2 = json_decode($client->getResponse()->getContent(), true);

        self::assertCount(50, $page2['items']);
        self::assertTrue($page2['pagination']['hasNextPage']);
        self::assertNotNull($page2['pagination']['nextCursor']);

        $page2Ids = array_column($page2['items'], 'id');

        // Fetch third page of remainder (at least 25 reports)
        $cursor2 = $page2['pagination']['nextCursor'];
        $client->request('GET', "/api/v1/moderation/queue?status=OPEN&limit=50&cursor={$cursor2}");
        self::assertResponseIsSuccessful();
        $page3 = json_decode($client->getResponse()->getContent(), true);

        self::assertGreaterThanOrEqual(25, \count($page3['items']));

        $page3Ids = array_column($page3['items'], 'id');

        // Verify NO duplicates across any of the pages!
        $allFetchedIds = array_merge($page1Ids, $page2Ids, $page3Ids);
        $uniqueFetchedIds = array_unique($allFetchedIds);
        self::assertCount(\count($allFetchedIds), $uniqueFetchedIds, 'Detected duplicate report IDs across cursor paginated pages!');
    }
}
