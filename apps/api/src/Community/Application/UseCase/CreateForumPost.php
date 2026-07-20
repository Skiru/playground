<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumCategoryRepository;
use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Uid\Uuid;

final class CreateForumPost
{
    public function __construct(
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $threadId, ?Uuid $replyToPostId, string $body): ForumPost
    {
        $thread = $this->threadRepository->findById($threadId);
        if (null === $thread || ForumThreadStatus::DELETED_BY_AUTHOR === $thread->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $thread->status() || ForumThreadStatus::HIDDEN === $thread->status()) {
            throw new ApiException(404, 'Thread not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Users cannot add posts to locked threads
        if (null !== $thread->lockedAt()) {
            throw new ApiException(400, 'Thread is locked.', 'LOCKED_THREAD');
        }

        $category = $this->categoryRepository->findById($thread->categoryId());
        if (null === $category || !$category->isActive()) {
            throw new ApiException(400, 'Category is inactive.', 'INACTIVE_CATEGORY');
        }

        // If replyToPostId is provided, check it exists and is in the same thread
        if (null !== $replyToPostId) {
            $parentPost = $this->postRepository->findById($replyToPostId);
            if (null === $parentPost || $parentPost->threadId()->toRfc4122() !== $threadId->toRfc4122()) {
                throw new ApiException(400, 'Replied-to post must belong to the same thread.', 'INVALID_PARENT_POST');
            }
        }

        $now = $this->clock->now();
        $post = new ForumPost(
            Uuid::v7(),
            $threadId,
            $userId,
            $replyToPostId,
            $body,
            ForumPostStatus::PUBLISHED,
            $now,
            $now
        );

        // Update thread's last activity
        $thread->updateLastActivity($now);

        $this->transactionManager->transactional(function () use ($thread, $post): void {
            $this->threadRepository->save($thread);
            $this->postRepository->save($post);
        });

        return $post;
    }
}
