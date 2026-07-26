<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

interface ForumPostRepository
{
    public function findById(Uuid $id): ?ForumPost;

    /** Load the post while holding its row lock; caller must already be in a transaction. */
    public function findByIdForUpdate(Uuid $id): ?ForumPost;

    public function findInitialByThreadId(Uuid $threadId): ?ForumPost;

    /** @return list<ForumPost> */
    public function findByThreadId(Uuid $threadId, ?string $cursorId, ?\DateTimeImmutable $cursorCreatedAt, int $limit): array;

    public function save(ForumPost $post): void;
}
