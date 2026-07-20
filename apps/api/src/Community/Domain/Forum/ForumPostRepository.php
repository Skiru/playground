<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

interface ForumPostRepository
{
    public function findById(Uuid $id): ?ForumPost;

    /** @return list<ForumPost> */
    public function findByThreadId(Uuid $threadId, ?string $cursorId, ?\DateTimeImmutable $cursorCreatedAt, int $limit): array;

    public function save(ForumPost $post): void;
}
