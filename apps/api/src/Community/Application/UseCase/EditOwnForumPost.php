<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class EditOwnForumPost
{
    public function __construct(
        private readonly ForumPostRepository $postRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $postId, int $expectedVersion, string $body): ForumPost
    {
        $post = $this->postRepository->findById($postId);
        if (null === $post || ForumPostStatus::DELETED_BY_AUTHOR === $post->status() || ForumPostStatus::REMOVED_BY_MODERATOR === $post->status()) {
            throw new ApiException(404, 'Post not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        if ($post->authorId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new ApiException(403, 'You cannot edit someone else\'s post.', 'FORBIDDEN_OWNERSHIP');
        }

        if ($post->version() !== $expectedVersion) {
            throw new ApiException(409, 'Post has been modified by another process.', 'CONCURRENCY_CONFLICT');
        }

        $post->edit($body, $this->clock->now());

        try {
            $this->postRepository->save($post);
        } catch (\RuntimeException $e) {
            if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                throw new ApiException(409, 'Post has been modified by another process.', 'CONCURRENCY_CONFLICT', '', [], $e);
            }
            throw $e;
        }

        return $post;
    }
}
