<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumThread;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class EditOwnForumThread
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $threadId, int $expectedVersion, string $title): ForumThread
    {
        $thread = $this->threadRepository->findById($threadId);
        if (null === $thread || ForumThreadStatus::DELETED_BY_AUTHOR === $thread->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $thread->status()) {
            throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        if ($thread->authorId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new ApiException(403, 'You cannot edit someone else\'s thread.', 'FORBIDDEN_OWNERSHIP');
        }

        if ($thread->version() !== $expectedVersion) {
            throw new ApiException(409, 'Thread has been modified by another process.', 'CONCURRENCY_CONFLICT');
        }

        $thread->editTitle($title, $this->clock->now());

        try {
            $this->threadRepository->save($thread);
        } catch (\RuntimeException $e) {
            if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                throw new ApiException(409, 'Thread has been modified by another process.', 'CONCURRENCY_CONFLICT', '', [], $e);
            }
            throw $e;
        }

        return $thread;
    }
}
