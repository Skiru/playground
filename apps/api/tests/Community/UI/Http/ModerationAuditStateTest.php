<?php

declare(strict_types=1);

namespace App\Tests\Community\UI\Http;

use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ModerationAuditStateTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

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

    private function getCsrfHeaders($client): array
    {
        $client->request('GET', '/api/v1/session');
        $sessionData = json_decode($client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'] ?? '';

        return [
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
    }

    private function setupReportAndClaim($client, string $targetType, Uuid $targetId, User $moderator): array
    {
        $reporter = $this->createUser('reporter_'.Uuid::v7()->toRfc4122().'@example.test', 'Reporter');
        $reportId = Uuid::v7();

        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at)
             VALUES (:id, :reporter_id, :target_type, :target_id, \'SPAM\', \'Test details\', \'OPEN\', NOW())',
            [
                'id' => $reportId->toRfc4122(),
                'reporter_id' => $reporter->getId()->toRfc4122(),
                'target_type' => $targetType,
                'target_id' => $targetId->toRfc4122(),
            ]
        );

        $client->loginUser($moderator);
        $headers = $this->getCsrfHeaders($client);
        $client->request('POST', \sprintf('/api/v1/moderation/case/%s/claim', $reportId->toRfc4122()), [], [], $headers);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        return [$reportId, $headers];
    }

    private function performAction($client, Uuid $reportId, string $action, array $headers): array
    {
        $idempotencyKey = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();
        $actionHeaders = $headers;
        $actionHeaders['HTTP_Idempotency-Key'] = $idempotencyKey;
        $actionHeaders['HTTP_X-Correlation-Id'] = $correlationId;

        $client->request(
            'POST',
            '/api/v1/moderation/action',
            [],
            [],
            $actionHeaders,
            (string) json_encode([
                'reportId' => $reportId->toRfc4122(),
                'action' => $action,
                'reason' => 'Testing audit state for '.$action,
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $auditRow = $this->connection->fetchAssociative(
            'SELECT action, previous_status, resulting_status, metadata FROM moderation_actions WHERE correlation_id = :cid OR report_id = :rid ORDER BY created_at DESC LIMIT 1',
            [
                'cid' => $correlationId,
                'rid' => $reportId->toRfc4122(),
            ]
        );

        $this->assertNotEmpty($auditRow);

        return $auditRow;
    }

    public function testAuditSemanticsAndAggregateStateForThreadActions(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->connection = static::getContainer()->get(Connection::class);

        $author = $this->createUser('author_'.Uuid::v7()->toRfc4122().'@example.test', 'Author');
        $moderator = $this->createUser('mod_audit_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Audit', ['ROLE_USER', 'ROLE_MODERATOR']);

        // Get category
        $categoryId = $this->connection->fetchOne('SELECT id FROM forum_categories LIMIT 1');

        // Create thread
        $threadId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at)
             VALUES (:id, :category_id, :author_id, \'Test Thread Audit\', \'PUBLISHED\', NOW(), NOW(), NOW())',
            [
                'id' => $threadId->toRfc4122(),
                'category_id' => $categoryId,
                'author_id' => $author->getId()->toRfc4122(),
            ]
        );

        // 1. LOCK action
        $mod1 = $this->createUser('mod_lock_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Lock', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId1, $headers1] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod1);
        $auditLock = $this->performAction($client, $reportId1, 'LOCK', $headers1);

        $threadRowLock = $this->connection->fetchAssociative('SELECT status, locked_at, pinned_at FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('PUBLISHED', $threadRowLock['status']); // Real aggregate status!
        $this->assertNotNull($threadRowLock['locked_at']); // Locked flag set
        $this->assertSame('PUBLISHED', $auditLock['previous_status']);
        $this->assertSame('PUBLISHED', $auditLock['resulting_status']); // NOT 'LOCKED'!
        $metaLock = json_decode($auditLock['metadata'], true);
        $this->assertTrue($metaLock['isLocked']);

        // 2. UNLOCK action
        $mod2 = $this->createUser('mod_unlock_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Unlock', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId2, $headers2] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod2);
        $auditUnlock = $this->performAction($client, $reportId2, 'UNLOCK', $headers2);

        $threadRowUnlock = $this->connection->fetchAssociative('SELECT status, locked_at FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('PUBLISHED', $threadRowUnlock['status']);
        $this->assertNull($threadRowUnlock['locked_at']);
        $this->assertSame('PUBLISHED', $auditUnlock['previous_status']);
        $this->assertSame('PUBLISHED', $auditUnlock['resulting_status']);
        $metaUnlock = json_decode($auditUnlock['metadata'], true);
        $this->assertFalse($metaUnlock['isLocked']);

        // 3. PIN action
        $mod3 = $this->createUser('mod_pin_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Pin', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId3, $headers3] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod3);
        $auditPin = $this->performAction($client, $reportId3, 'PIN', $headers3);

        $threadRowPin = $this->connection->fetchAssociative('SELECT status, pinned_at FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('PUBLISHED', $threadRowPin['status']);
        $this->assertNotNull($threadRowPin['pinned_at']);
        $this->assertSame('PUBLISHED', $auditPin['previous_status']);
        $this->assertSame('PUBLISHED', $auditPin['resulting_status']);
        $metaPin = json_decode($auditPin['metadata'], true);
        $this->assertTrue($metaPin['isPinned']);

        // 4. UNPIN action
        $mod4 = $this->createUser('mod_unpin_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Unpin', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId4, $headers4] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod4);
        $auditUnpin = $this->performAction($client, $reportId4, 'UNPIN', $headers4);

        $threadRowUnpin = $this->connection->fetchAssociative('SELECT status, pinned_at FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('PUBLISHED', $threadRowUnpin['status']);
        $this->assertNull($threadRowUnpin['pinned_at']);
        $this->assertSame('PUBLISHED', $auditUnpin['previous_status']);
        $this->assertSame('PUBLISHED', $auditUnpin['resulting_status']);
        $metaUnpin = json_decode($auditUnpin['metadata'], true);
        $this->assertFalse($metaUnpin['isPinned']);

        // 5. HIDE action
        $mod5 = $this->createUser('mod_hide_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Hide', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId5, $headers5] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod5);
        $auditHide = $this->performAction($client, $reportId5, 'HIDE', $headers5);

        $threadRowHide = $this->connection->fetchAssociative('SELECT status FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('HIDDEN', $threadRowHide['status']);
        $this->assertSame('PUBLISHED', $auditHide['previous_status']);
        $this->assertSame('HIDDEN', $auditHide['resulting_status']);

        // 6. RESTORE action
        $mod6 = $this->createUser('mod_restore_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Restore', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId6, $headers6] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod6);
        $auditRestore = $this->performAction($client, $reportId6, 'RESTORE', $headers6);

        $threadRowRestore = $this->connection->fetchAssociative('SELECT status FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('PUBLISHED', $threadRowRestore['status']);
        $this->assertSame('HIDDEN', $auditRestore['previous_status']);
        $this->assertSame('PUBLISHED', $auditRestore['resulting_status']);

        // 7. REMOVE action
        $mod7 = $this->createUser('mod_remove_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Remove', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId7, $headers7] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $mod7);
        $auditRemove = $this->performAction($client, $reportId7, 'REMOVE', $headers7);

        $threadRowRemove = $this->connection->fetchAssociative('SELECT status FROM forum_threads WHERE id = :id', ['id' => $threadId->toRfc4122()]);
        $this->assertSame('REMOVED_BY_MODERATOR', $threadRowRemove['status']);
        $this->assertSame('PUBLISHED', $auditRemove['previous_status']);
        $this->assertSame('REMOVED_BY_MODERATOR', $auditRemove['resulting_status']);
    }

    public function testAuditForResolveAndDismissReport(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->connection = static::getContainer()->get(Connection::class);

        $author = $this->createUser('author2_'.Uuid::v7()->toRfc4122().'@example.test', 'Author 2');
        $moderator = $this->createUser('mod_audit2_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Audit 2', ['ROLE_USER', 'ROLE_MODERATOR']);
        $categoryId = $this->connection->fetchOne('SELECT id FROM forum_categories LIMIT 1');

        $threadId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at)
             VALUES (:id, :category_id, :author_id, \'Test Thread Resolve\', \'PUBLISHED\', NOW(), NOW(), NOW())',
            [
                'id' => $threadId->toRfc4122(),
                'category_id' => $categoryId,
                'author_id' => $author->getId()->toRfc4122(),
            ]
        );

        // RESOLVE_REPORT
        $modResolve = $this->createUser('mod_audit_res_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Audit Res', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId1, $headers1] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $modResolve);
        $auditResolve = $this->performAction($client, $reportId1, 'RESOLVE_REPORT', $headers1);

        $reportRow1 = $this->connection->fetchAssociative('SELECT status FROM content_reports WHERE id = :id', ['id' => $reportId1->toRfc4122()]);
        $this->assertSame('RESOLVED', $reportRow1['status']);
        $this->assertSame('PUBLISHED', $auditResolve['previous_status']);
        $this->assertSame('PUBLISHED', $auditResolve['resulting_status']);

        // DISMISS_REPORT
        $modDismiss = $this->createUser('mod_audit_dis_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Audit Dis', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$reportId2, $headers2] = $this->setupReportAndClaim($client, 'FORUM_THREAD', $threadId, $modDismiss);
        $auditDismiss = $this->performAction($client, $reportId2, 'DISMISS_REPORT', $headers2);

        $reportRow2 = $this->connection->fetchAssociative('SELECT status FROM content_reports WHERE id = :id', ['id' => $reportId2->toRfc4122()]);
        $this->assertSame('DISMISSED', $reportRow2['status']);
        $this->assertSame('PUBLISHED', $auditDismiss['previous_status']);
        $this->assertSame('PUBLISHED', $auditDismiss['resulting_status']);
    }
}
