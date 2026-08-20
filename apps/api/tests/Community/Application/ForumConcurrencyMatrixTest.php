<?php

declare(strict_types=1);

namespace App\Tests\Community\Application;

use App\Community\Application\UseCase\CreateForumPost;
use App\Community\Application\UseCase\DeleteOwnForumPost;
use App\Community\Application\UseCase\DeleteOwnForumThread;
use App\Community\Application\UseCase\EditOwnForumPost;
use App\Community\Application\UseCase\EditOwnForumThread;
use App\Community\Application\UseCase\GetModerationCase;
use App\Community\Application\UseCase\ModerateContent;
use App\Community\Domain\Moderation\ModerationActionType;
use App\Identity\Domain\User;
use App\Shared\Application\Exception\ApiException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ForumConcurrencyMatrixTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EditOwnForumThread $editThreadUseCase;
    private EditOwnForumPost $editPostUseCase;
    private DeleteOwnForumThread $deleteThreadUseCase;
    private DeleteOwnForumPost $deletePostUseCase;
    private CreateForumPost $createPostUseCase;
    private ModerateContent $moderateContentUseCase;
    private GetModerationCase $getCaseUseCase;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->connection = $container->get(Connection::class);

        $this->editThreadUseCase = $container->get(EditOwnForumThread::class);
        $this->editPostUseCase = $container->get(EditOwnForumPost::class);
        $this->deleteThreadUseCase = $container->get(DeleteOwnForumThread::class);
        $this->deletePostUseCase = $container->get(DeleteOwnForumPost::class);
        $this->createPostUseCase = $container->get(CreateForumPost::class);
        $this->moderateContentUseCase = $container->get(ModerateContent::class);
        $this->getCaseUseCase = $container->get(GetModerationCase::class);
    }

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

    private function createThreadAndInitialPost(User $author): array
    {
        $categoryId = $this->connection->fetchOne('SELECT id FROM forum_categories WHERE active = true LIMIT 1');
        $threadId = Uuid::v7();
        $initialPostId = Uuid::v7();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO forum_threads (id, category_id, author_id, title, status, version, created_at, updated_at, last_activity_at)
             VALUES (:id, :category_id, :author_id, \'Concurrency Thread Title\', \'PUBLISHED\', 1, :now, :now, :now)',
            [
                'id' => $threadId->toRfc4122(),
                'category_id' => $categoryId,
                'author_id' => $author->getId()->toRfc4122(),
                'now' => $now,
            ]
        );

        $this->connection->executeStatement(
            'INSERT INTO forum_posts (id, thread_id, author_id, parent_id, body, status, is_initial, version, created_at, updated_at)
             VALUES (:id, :thread_id, :author_id, NULL, \'Initial post body\', \'PUBLISHED\', true, 1, :now, :now)',
            [
                'id' => $initialPostId->toRfc4122(),
                'thread_id' => $threadId->toRfc4122(),
                'author_id' => $author->getId()->toRfc4122(),
                'now' => $now,
            ]
        );

        return [$threadId, $initialPostId];
    }

    // 1. edit vs edit
    public function testEditVsEditOptimisticLock(): void
    {
        $author = $this->createUser('edit1_'.Uuid::v7()->toRfc4122().'@example.test', 'Author Edit 1');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // First edit expected version 1 -> succeeds, version becomes 2
        $this->editThreadUseCase->execute($author->getId(), $threadId, 1, 'Updated Title First');

        // Concurrent edit with stale version 1 -> fails with 409 CONCURRENCY_CONFLICT
        try {
            $this->editThreadUseCase->execute($author->getId(), $threadId, 1, 'Updated Title Second');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('CONCURRENCY_CONFLICT', $e->getErrorCode());
        }
    }

    // 2. delete vs edit
    public function testDeleteVsEditConflict(): void
    {
        $author = $this->createUser('deledit_'.Uuid::v7()->toRfc4122().'@example.test', 'Author DelEdit');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Author deletes thread
        $this->deleteThreadUseCase->execute($author->getId(), $threadId);

        // Edit deleted thread fails with 404
        try {
            $this->editThreadUseCase->execute($author->getId(), $threadId, 1, 'New Title After Delete');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // 3. delete thread vs create reply
    public function testDeleteThreadVsCreateReply(): void
    {
        $author = $this->createUser('delreply_'.Uuid::v7()->toRfc4122().'@example.test', 'Author DelReply');
        $replyUser = $this->createUser('replier_'.Uuid::v7()->toRfc4122().'@example.test', 'Replier');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Author deletes thread
        $this->deleteThreadUseCase->execute($author->getId(), $threadId);

        // Creating reply in deleted thread fails with 404
        try {
            $this->createPostUseCase->execute($replyUser->getId(), $threadId, null, 'Reply on deleted thread');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // 4. lock vs create reply
    public function testLockVsCreateReply(): void
    {
        $author = $this->createUser('lockreply_'.Uuid::v7()->toRfc4122().'@example.test', 'Author LockReply');
        $mod = $this->createUser('modlock_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Lock', ['ROLE_USER', 'ROLE_MODERATOR']);
        $replyUser = $this->createUser('replier2_'.Uuid::v7()->toRfc4122().'@example.test', 'Replier 2');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Lock thread
        $reportId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at, claimed_by, claimed_at)
             VALUES (:id, :reporter, \'FORUM_THREAD\', :target, \'SPAM\', \'Lock test\', \'IN_REVIEW\', NOW(), :mod, NOW())',
            ['id' => $reportId->toRfc4122(), 'reporter' => $replyUser->getId()->toRfc4122(), 'target' => $threadId->toRfc4122(), 'mod' => $mod->getId()->toRfc4122()]
        );

        $this->moderateContentUseCase->execute($mod->getId(), $reportId, ModerationActionType::LOCK, 'Locking thread', Uuid::v7()->toRfc4122());

        // Creating reply on locked thread fails with 409 THREAD_WRITE_CONFLICT
        try {
            $this->createPostUseCase->execute($replyUser->getId(), $threadId, null, 'Reply on locked thread');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('THREAD_WRITE_CONFLICT', $e->getErrorCode());
        }
    }

    // 5. hide vs create reply
    public function testHideVsCreateReply(): void
    {
        $author = $this->createUser('hidereply_'.Uuid::v7()->toRfc4122().'@example.test', 'Author HideReply');
        $mod = $this->createUser('modhide_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Hide', ['ROLE_USER', 'ROLE_MODERATOR']);
        $replyUser = $this->createUser('replier3_'.Uuid::v7()->toRfc4122().'@example.test', 'Replier 3');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Hide thread
        $reportId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at, claimed_by, claimed_at)
             VALUES (:id, :reporter, \'FORUM_THREAD\', :target, \'SPAM\', \'Hide test\', \'IN_REVIEW\', NOW(), :mod, NOW())',
            ['id' => $reportId->toRfc4122(), 'reporter' => $replyUser->getId()->toRfc4122(), 'target' => $threadId->toRfc4122(), 'mod' => $mod->getId()->toRfc4122()]
        );

        $this->moderateContentUseCase->execute($mod->getId(), $reportId, ModerationActionType::HIDE, 'Hiding thread', Uuid::v7()->toRfc4122());

        // Creating reply on hidden thread fails with 404
        try {
            $this->createPostUseCase->execute($replyUser->getId(), $threadId, null, 'Reply on hidden thread');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // 6. moderator hide vs author edit
    public function testModeratorHideVsAuthorEdit(): void
    {
        $author = $this->createUser('hideedit_'.Uuid::v7()->toRfc4122().'@example.test', 'Author HideEdit');
        $mod = $this->createUser('modhide2_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Hide 2', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Moderator hides thread
        $reportId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at, claimed_by, claimed_at)
             VALUES (:id, :reporter, \'FORUM_THREAD\', :target, \'SPAM\', \'Hide edit test\', \'IN_REVIEW\', NOW(), :mod, NOW())',
            ['id' => $reportId->toRfc4122(), 'reporter' => $author->getId()->toRfc4122(), 'target' => $threadId->toRfc4122(), 'mod' => $mod->getId()->toRfc4122()]
        );
        $this->moderateContentUseCase->execute($mod->getId(), $reportId, ModerationActionType::HIDE, 'Hiding thread', Uuid::v7()->toRfc4122());

        // Author editing hidden thread fails with 409 CONTENT_STATE_CONFLICT
        try {
            $this->editThreadUseCase->execute($author->getId(), $threadId, 1, 'Author editing hidden thread');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertContains($e->getErrorCode(), ['CONCURRENCY_CONFLICT', 'CONTENT_STATE_CONFLICT']);
        }
    }

    // 7. moderator remove vs author delete
    public function testModeratorRemoveVsAuthorDelete(): void
    {
        $author = $this->createUser('remdel_'.Uuid::v7()->toRfc4122().'@example.test', 'Author RemDel');
        $mod = $this->createUser('modrem_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Rem', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Moderator removes thread
        $reportId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at, claimed_by, claimed_at)
             VALUES (:id, :reporter, \'FORUM_THREAD\', :target, \'SPAM\', \'Remove test\', \'IN_REVIEW\', NOW(), :mod, NOW())',
            ['id' => $reportId->toRfc4122(), 'reporter' => $author->getId()->toRfc4122(), 'target' => $threadId->toRfc4122(), 'mod' => $mod->getId()->toRfc4122()]
        );
        $this->moderateContentUseCase->execute($mod->getId(), $reportId, ModerationActionType::REMOVE, 'Removing thread', Uuid::v7()->toRfc4122());

        // Author deleting removed thread fails with 404 MISSING_PUBLIC_RESOURCE
        try {
            $this->deleteThreadUseCase->execute($author->getId(), $threadId);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // 8. report vs target deletion
    public function testReportVsTargetDeletionHandling(): void
    {
        $author = $this->createUser('reportdel_'.Uuid::v7()->toRfc4122().'@example.test', 'Author ReportDel');
        $mod = $this->createUser('modcase_'.Uuid::v7()->toRfc4122().'@example.test', 'Mod Case', ['ROLE_USER', 'ROLE_MODERATOR']);
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Report created
        $reportId = Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at)
             VALUES (:id, :reporter, \'FORUM_THREAD\', :target, \'SPAM\', \'Report before del\', \'OPEN\', NOW())',
            ['id' => $reportId->toRfc4122(), 'reporter' => $mod->getId()->toRfc4122(), 'target' => $threadId->toRfc4122()]
        );

        // Target thread deleted by author
        $this->deleteThreadUseCase->execute($author->getId(), $threadId);

        // Moderator views case -> handles missing/deleted target preview gracefully without crash
        $caseData = $this->getCaseUseCase->execute($reportId);
        $this->assertSame($reportId->toString(), $caseData['id']);
        $this->assertSame('DELETED_BY_AUTHOR', $caseData['targetPreview']['status']);
    }

    // 9. stale version edit
    public function testStaleVersionPostEdit(): void
    {
        $author = $this->createUser('stale_'.Uuid::v7()->toRfc4122().'@example.test', 'Author Stale');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // Create post
        $post = $this->createPostUseCase->execute($author->getId(), $threadId, null, 'Post v1 body');
        $this->assertSame(1, $post->version());

        // First edit updates version to 2
        $this->editPostUseCase->execute($author->getId(), $post->id(), 1, 'Post v2 body');

        // Edit using stale version 1 fails with 409 CONCURRENCY_CONFLICT
        try {
            $this->editPostUseCase->execute($author->getId(), $post->id(), 1, 'Post v3 body with stale version 1');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('CONCURRENCY_CONFLICT', $e->getErrorCode());
        }
    }

    // 10. concurrent thread activity update
    public function testConcurrentThreadActivityUpdate(): void
    {
        $author = $this->createUser('act_'.Uuid::v7()->toRfc4122().'@example.test', 'Author Act');
        $user1 = $this->createUser('u1_act_'.Uuid::v7()->toRfc4122().'@example.test', 'User Act 1');
        $user2 = $this->createUser('u2_act_'.Uuid::v7()->toRfc4122().'@example.test', 'User Act 2');
        [$threadId] = $this->createThreadAndInitialPost($author);

        // User 1 replies
        $post1 = $this->createPostUseCase->execute($user1->getId(), $threadId, null, 'Reply 1');
        // User 2 replies
        $post2 = $this->createPostUseCase->execute($user2->getId(), $threadId, null, 'Reply 2');

        $this->assertNotNull($post1);
        $this->assertNotNull($post2);

        $postCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_posts WHERE thread_id = :tid', ['tid' => $threadId->toRfc4122()]);
        $this->assertSame(3, $postCount); // Initial post + 2 replies
    }
}
