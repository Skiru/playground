<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class DeleteOwnForumThread
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $threadId): void
    {
        $thread = $this->threadRepository->findById($threadId);
        if (null === $thread || ForumThreadStatus::DELETED_BY_AUTHOR === $thread->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $thread->status()) {
            throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        if ($thread->authorId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new ApiException(403, 'You cannot delete someone else\'s thread.', 'FORBIDDEN_OWNERSHIP');
        }

        $thread->softDelete($this->clock->now());
        $this->threadRepository->save($thread);
    }
}
