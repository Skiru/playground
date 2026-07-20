<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Community\Domain\Forum\ForumCategory;
use App\Community\Domain\Forum\ForumCategoryRepository;
use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThread;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Community\Domain\Moderation\ContentReport;
use App\Community\Domain\Moderation\ContentReportRepository;
use App\Community\Domain\Moderation\ReportReason;
use App\Community\Domain\Moderation\ReportStatus;
use App\Community\Domain\Moderation\TargetType;
use App\Community\Domain\PlaceDiscussion\PlaceComment;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
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
            roles: $roles
        );

        // Reflection setting for UserStatus
        $ref = new \ReflectionClass($user);
        $prop = $ref->getProperty('status');
        $prop->setAccessible(true);
        $prop->setValue($user, UserStatus::ACTIVE);

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
        $secretThreadTitle = 'SECRET_MARKER_HIDDEN_THREAD_' . random_int(10000, 99999);
        $secretPostText = 'SECRET_MARKER_POST_IN_INACTIVE_CAT_' . random_int(10000, 99999);
        $secretReviewText = 'SECRET_MARKER_REVIEW_UNPUBLISHED_PLACE_' . random_int(10000, 99999);
        $secretCommentText = 'SECRET_MARKER_COMMENT_UNPUBLISHED_PLACE_' . random_int(10000, 99999);

        // 1. Inactive category and a published thread/post inside it
        $inactiveCategoryUuid = Uuid::v7();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, :display_order, :active)',
            [
                'id' => $inactiveCategoryUuid->toRfc4122(),
                'slug' => 'inactive-cat-' . random_int(1000, 9999),
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
                'slug' => 'secret-playroom-' . random_int(100, 999),
                'name' => 'Secret Playroom',
                'normalized_name' => 'secret playroom',
                'short_description' => 'Short draft',
                'description' => 'Draft description',
                'status' => 'draft', // NOT published!
                'verification_status' => 'verified',
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
        \fwrite(STDERR, "\n--- INACCESSIBLE REPORT RESPONSE ---\n" . $content . "\n-------------------\n");

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
                'slug' => 'test-cat-reply-' . random_int(1000, 9999),
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
        \fwrite(STDERR, "\n--- FIRST REPORT RESPONSE ---\n" . $content1 . "\n-------------------\n");
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        // Submit duplicate report immediately and check 409
        $client->request('POST', '/api/v1/content-reports', [], [], $csrfHeaders, json_encode([
            'targetId' => $reviewUuid->toRfc4122(),
            'targetType' => 'REVIEW',
            'reason' => 'SPAM',
            'details' => 'Spamming again',
        ]));
        
        $content2 = $client->getResponse()->getContent();
        \fwrite(STDERR, "\n--- SECOND REPORT RESPONSE ---\n" . $content2 . "\n-------------------\n");
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        $res = json_decode($content2, true);
        self::assertSame('REPORT_ALREADY_EXISTS', $res['code']);
    }
}
