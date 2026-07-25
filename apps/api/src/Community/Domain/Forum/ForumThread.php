<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

final class ForumThread
{
    private Uuid $id;
    private Uuid $categoryId;
    private Uuid $authorId;
    private string $title;
    private ForumThreadStatus $status;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private \DateTimeImmutable $lastActivityAt;
    private ?\DateTimeImmutable $lockedAt;
    private ?\DateTimeImmutable $pinnedAt;
    private int $version;

    public function __construct(
        Uuid $id,
        Uuid $categoryId,
        Uuid $authorId,
        string $title,
        ForumThreadStatus $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $lastActivityAt,
        ?\DateTimeImmutable $lockedAt = null,
        ?\DateTimeImmutable $pinnedAt = null,
        int $version = 1,
    ) {
        $trimmedTitle = trim($title);
        $len = mb_strlen($trimmedTitle);
        if ($len < 5 || $len > 160) {
            throw new \InvalidArgumentException('Thread title must be between 5 and 160 characters.');
        }

        $this->id = $id;
        $this->categoryId = $categoryId;
        $this->authorId = $authorId;
        $this->title = $trimmedTitle;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->lastActivityAt = $lastActivityAt;
        $this->lockedAt = $lockedAt;
        $this->pinnedAt = $pinnedAt;
        $this->version = $version;
    }

    public function editTitle(string $title, \DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status) {
            throw new \LogicException('Hidden, removed, or deleted thread cannot be edited by its author.');
        }

        $trimmedTitle = trim($title);
        $len = mb_strlen($trimmedTitle);
        if ($len < 5 || $len > 160) {
            throw new \InvalidArgumentException('Thread title must be between 5 and 160 characters.');
        }

        $this->title = $trimmedTitle;
        $this->updatedAt = $now;
    }

    public function softDelete(\DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [ForumThreadStatus::PUBLISHED, ForumThreadStatus::HIDDEN], true)) {
            throw new \LogicException('Only published or hidden threads can be deleted by their author.');
        }
        $this->status = ForumThreadStatus::DELETED_BY_AUTHOR;
        $this->updatedAt = $now;
    }

    public function hide(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status) {
            throw new \LogicException('Only published threads can be hidden.');
        }
        $this->status = ForumThreadStatus::HIDDEN;
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::HIDDEN !== $this->status) {
            throw new \LogicException('Only hidden threads can be restored.');
        }
        $this->status = ForumThreadStatus::PUBLISHED;
        $this->updatedAt = $now;
    }

    public function removeByModerator(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status) {
            throw new \LogicException('Only published threads can be removed.');
        }
        $this->status = ForumThreadStatus::REMOVED_BY_MODERATOR;
        $this->updatedAt = $now;
    }

    public function lock(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status || null !== $this->lockedAt) {
            throw new \LogicException('Only an unlocked published thread can be locked.');
        }
        $this->lockedAt = $now;
        $this->updatedAt = $now;
    }

    public function unlock(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status || null === $this->lockedAt) {
            throw new \LogicException('Only a locked published thread can be unlocked.');
        }
        $this->lockedAt = null;
        $this->updatedAt = $now;
    }

    public function pin(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status || null !== $this->pinnedAt) {
            throw new \LogicException('Only an unpinned published thread can be pinned.');
        }
        $this->pinnedAt = $now;
        $this->updatedAt = $now;
    }

    public function unpin(\DateTimeImmutable $now): void
    {
        if (ForumThreadStatus::PUBLISHED !== $this->status || null === $this->pinnedAt) {
            throw new \LogicException('Only a pinned published thread can be unpinned.');
        }
        $this->pinnedAt = null;
        $this->updatedAt = $now;
    }

    public function updateLastActivity(\DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
    }

    public function advanceVersion(): void
    {
        ++$this->version;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function categoryId(): Uuid
    {
        return $this->categoryId;
    }

    public function authorId(): Uuid
    {
        return $this->authorId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): ForumThreadStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function lastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function lockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function pinnedAt(): ?\DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
