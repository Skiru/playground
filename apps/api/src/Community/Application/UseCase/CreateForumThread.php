<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumCategoryRepository;
use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThread;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Uid\Uuid;

final class CreateForumThread
{
    public function __construct(
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{thread: ForumThread, firstPost: ForumPost}
     */
    public function execute(Uuid $userId, Uuid $categoryId, string $title, string $firstPostBody): array
    {
        $category = $this->categoryRepository->findById($categoryId);
        if (null === $category || !$category->isActive()) {
            throw new ApiException(400, 'Inactive category or category not found.', 'INACTIVE_CATEGORY');
        }

        $now = $this->clock->now();
        $threadId = Uuid::v7();
        $postId = Uuid::v7();

        $thread = new ForumThread(
            $threadId,
            $categoryId,
            $userId,
            $title,
            ForumThreadStatus::PUBLISHED,
            $now,
            $now,
            $now
        );

        $post = new ForumPost(
            $postId,
            $threadId,
            $userId,
            null,
            $firstPostBody,
            ForumPostStatus::PUBLISHED,
            $now,
            $now
        );

        // Perform transactional atomic insert
        $this->transactionManager->transactional(function () use ($thread, $post): void {
            $this->threadRepository->save($thread);
            $this->postRepository->save($post);
        });

        return [
            'thread' => $thread,
            'firstPost' => $post,
        ];
    }
}
