<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ForumAndModerationSecurityTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private function createUser(string $email, string $displayName, array $roles = ['ROLE_USER'], UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = new User(
            email: new \App\Identity\Domain\ValueObject\EmailAddress($email),
            displayName: $displayName,
            createdAt: new \DateTimeImmutable(),
            roles: $roles
        );

        // We set status via reflection since it is private with no public setter
        $ref = new \ReflectionClass($user);
        $prop = $ref->getProperty('status');
        $prop->setValue($user, $status);

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

    public function testSecurityAndValidationMatrix(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        // Create Alice, Bob, Inactive, and Moderator
        $alice = $this->createUser(\sprintf('alice-%d@example.com', random_int(10000, 99999)), 'Alice');
        $bob = $this->createUser(\sprintf('bob-%d@example.com', random_int(10000, 99999)), 'Bob');
        $suspended = $this->createUser(\sprintf('suspended-%d@example.com', random_int(10000, 99999)), 'Suspended', ['ROLE_USER'], UserStatus::SUSPENDED);
        $moderator = $this->createUser(\sprintf('mod-%d@example.com', random_int(10000, 99999)), 'Moderator', ['ROLE_MODERATOR']);

        // Set up a Forum Category to test with
        $categoryUuid = Uuid::v7();
        $categorySlug = 'test-security-category-'.random_int(100, 999);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO forum_categories (id, slug, name, description, display_order, active) VALUES (:id, :slug, :name, :description, :display_order, :active)',
            [
                'id' => $categoryUuid->toRfc4122(),
                'slug' => $categorySlug,
                'name' => 'Security Testing Category',
                'description' => 'Category for testing security and moderation logic.',
                'display_order' => 1,
                'active' => 1,
            ]
        );

        // 1. Log Alice in and get CSRF Token
        $client->loginUser($alice);
        $client->request('GET', '/api/v1/session');
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $sessionData = json_decode($content, true);
        $csrfToken = $sessionData['csrfToken'];
        $csrfHeaders = $this->getCsrfHeaders($csrfToken);

        // Logout Alice to make the client anonymous
        $client->request('POST', '/api/v1/logout', [], [], $csrfHeaders);
        self::assertResponseIsSuccessful();

        // 2. Anonymous visitor read-only access to categories and threads
        $client->request('GET', '/api/v1/forum/categories');
        self::assertResponseIsSuccessful();

        // 3. Anonymous visitor POST / write block (401 expected)
        $client->request('POST', \sprintf('/api/v1/forum/categories/%s/threads', $categoryUuid->toRfc4122()), [], [], $csrfHeaders, json_encode([
            'title' => 'Anonymous thread title of 5-160 chars',
            'body' => 'Anonymous body of more than 1 char.',
        ]));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        // 4. Inactive/Suspended user write block (403 expected)
        $client->loginUser($suspended);
        $client->request('POST', \sprintf('/api/v1/forum/categories/%s/threads', $categoryUuid->toRfc4122()), [], [], $csrfHeaders, json_encode([
            'title' => 'Suspended thread title',
            'body' => 'Suspended body.',
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('INACTIVE_ACCOUNT', $client->getResponse()->getContent());

        // 5. Alice successfully creates a thread
        $client->loginUser($alice);
        $client->request('POST', \sprintf('/api/v1/forum/categories/%s/threads', $categoryUuid->toRfc4122()), [], [], $csrfHeaders, json_encode([
            'title' => 'Valid Thread Title by Alice',
            'body' => 'Initial post body for Alice thread.',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        $threadData = json_decode($client->getResponse()->getContent(), true);
        $threadId = $threadData['id'];

        // 6. CSRF Protection Block: Alice tries to create a post without a CSRF token
        $client->request('POST', \sprintf('/api/v1/forum/threads/%s/posts', $threadId), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'body' => 'Post without CSRF token.',
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('CSRF_TOKEN_MISSING', $client->getResponse()->getContent());

        // 7. Payload validation: Oversized and extra-field block
        $client->request('POST', \sprintf('/api/v1/forum/threads/%s/posts', $threadId), [], [], $csrfHeaders, json_encode([
            'body' => 'Alice post with extra fields.',
            'extra_unwanted_field' => 'danger-value',
        ]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('extra_unwanted_field', $client->getResponse()->getContent());
        self::assertStringContainsString('is not allowed.', $client->getResponse()->getContent());

        // 8. Bob tries to edit or delete Alice's thread (403 expected)
        $client->loginUser($bob);
        $client->request('PATCH', \sprintf('/api/v1/me/forum-threads/%s', $threadId), [], [], $csrfHeaders, json_encode([
            'title' => 'Malicious edit by Bob',
            'version' => 1,
        ]));
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('FORBIDDEN_OWNERSHIP', $client->getResponse()->getContent());

        $client->request('DELETE', \sprintf('/api/v1/me/forum-threads/%s', $threadId), [], [], $csrfHeaders);
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        // 9. Alice can successfully edit her own thread
        $client->loginUser($alice);
        $client->request('PATCH', \sprintf('/api/v1/me/forum-threads/%s', $threadId), [], [], $csrfHeaders, json_encode([
            'title' => 'Updated Thread Title by Alice',
            'version' => 1,
        ]));
        self::assertResponseIsSuccessful();

        // 10. Reporting: Bob reports Alice's thread
        $client->loginUser($bob);
        $client->request('POST', '/api/v1/content-reports', [], [], $csrfHeaders, json_encode([
            'targetId' => $threadId,
            'targetType' => 'FORUM_THREAD',
            'reason' => 'SPAM',
            'details' => 'Bob thinks Alice is spamming.',
        ]));
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        // 11. Report deduplication: Bob tries to report the exact same thread again (409 expected)
        $client->request('POST', '/api/v1/content-reports', [], [], $csrfHeaders, json_encode([
            'targetId' => $threadId,
            'targetType' => 'FORUM_THREAD',
            'reason' => 'HARASSMENT',
            'details' => 'Bob duplicate report.',
        ]));
        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('DUPLICATE_REPORT', $client->getResponse()->getContent());

        // 12. Non-moderator role check: Bob tries to access moderation queue (403 expected)
        $client->request('GET', '/api/v1/moderation/queue');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('MODERATOR_ROLE_REQUIRED', $client->getResponse()->getContent());

        // 13. Moderator role is enforced and can view queue and moderate
        $client->loginUser($moderator);
        $client->request('GET', '/api/v1/moderation/queue');
        self::assertResponseIsSuccessful();
        $queueData = json_decode($client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, \count($queueData['items']));

        // Moderator hides Alice's thread
        $client->request('POST', '/api/v1/moderation/action', [], [], $csrfHeaders, json_encode([
            'targetId' => $threadId,
            'targetType' => 'FORUM_THREAD',
            'action' => 'HIDE',
            'reason' => 'Thread contains spam as reported by Bob.',
        ]));
        self::assertResponseIsSuccessful();

        // 14. Leak check: Alice's hidden thread should not leak in the public categories view
        $client->loginUser($alice);
        $client->request('GET', \sprintf('/api/v1/forum/categories/%s/threads', $categorySlug));
        self::assertResponseIsSuccessful();
        $publicThreads = json_decode($client->getResponse()->getContent(), true);

        $found = false;
        foreach ($publicThreads['items'] as $item) {
            if ($item['id'] === $threadId) {
                $found = true;
                break;
            }
        }
        self::assertFalse($found, 'Hidden thread leaked in public listings!');
    }
}
