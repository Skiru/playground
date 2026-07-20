<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

interface ForumThreadRepository
{
    public function findById(Uuid $id): ?ForumThread;

    /** @return list<ForumThread> */
    public function findByCategoryId(Uuid $categoryId, ?string $cursorId, ?\DateTimeImmutable $cursorPinnedAt, ?\DateTimeImmutable $cursorLastActivityAt, int $limit): array;

    public function save(ForumThread $thread): void;
}
