<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DeleteOwnForumThread
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly Connection $connection,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $threadId): void
    {
        $this->transactionManager->transactional(function () use ($userId, $threadId): void {
            $this->connection->fetchOne(
                'SELECT id FROM forum_threads WHERE id = :id FOR UPDATE',
                ['id' => $threadId->toRfc4122()],
            );
            $thread = $this->threadRepository->findById($threadId);
            if (null === $thread || ForumThreadStatus::DELETED_BY_AUTHOR === $thread->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $thread->status()) {
                throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
            }
            if ($thread->authorId()->toRfc4122() !== $userId->toRfc4122()) {
                throw new ApiException(403, 'You cannot delete someone else\'s thread.', 'FORBIDDEN_OWNERSHIP');
            }

            $firstPost = $this->postRepository->findInitialByThreadId($threadId);
            if (null === $firstPost) {
                throw new ApiException(409, 'Thread initial post is missing.', 'CONTENT_STATE_CONFLICT');
            }

            $now = $this->clock->now();
            $thread->softDelete($now);
            $shouldSaveInitialPost = false;

            if (\in_array($firstPost->status(), [ForumPostStatus::PUBLISHED, ForumPostStatus::HIDDEN], true)) {
                $firstPost->softDelete($now);
                $shouldSaveInitialPost = true;
            }

            try {
                $this->threadRepository->save($thread);
                if ($shouldSaveInitialPost) {
                    $this->postRepository->save($firstPost);
                }
            } catch (\RuntimeException $e) {
                if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                    throw new ApiException(409, 'Thread has been modified by another process.', 'CONCURRENCY_CONFLICT', '', [], $e);
                }

                throw $e;
            }
        });
    }
}
