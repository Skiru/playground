<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

final class ForumPost
{
    private Uuid $id;
    private Uuid $threadId;
    private Uuid $authorId;
    private ?Uuid $parentId;
    private string $body;
    private ForumPostStatus $status;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version;

    public function __construct(
        Uuid $id,
        Uuid $threadId,
        Uuid $authorId,
        ?Uuid $parentId,
        string $body,
        ForumPostStatus $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version = 1,
    ) {
        $trimmedBody = trim($body);
        $len = mb_strlen($trimmedBody);
        if ($len < 1 || $len > 10000) {
            throw new \InvalidArgumentException('Post body must be between 1 and 10000 characters.');
        }

        $this->id = $id;
        $this->threadId = $threadId;
        $this->authorId = $authorId;
        $this->parentId = $parentId;
        $this->body = $trimmedBody;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->version = $version;
    }

    public function edit(string $body, \DateTimeImmutable $now): void
    {
        if (ForumPostStatus::PUBLISHED !== $this->status) {
            throw new \LogicException('Deleted, hidden, or removed post cannot be edited.');
        }

        $trimmedBody = trim($body);
        $len = mb_strlen($trimmedBody);
        if ($len < 1 || $len > 10000) {
            throw new \InvalidArgumentException('Post body must be between 1 and 10000 characters.');
        }

        $this->body = $trimmedBody;
        $this->updatedAt = $now;
    }

    public function softDelete(\DateTimeImmutable $now): void
    {
        $this->status = ForumPostStatus::DELETED_BY_AUTHOR;
        $this->updatedAt = $now;
    }

    public function hide(\DateTimeImmutable $now): void
    {
        $this->status = ForumPostStatus::HIDDEN;
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $this->status = ForumPostStatus::PUBLISHED;
        $this->updatedAt = $now;
    }

    public function removeByModerator(\DateTimeImmutable $now): void
    {
        $this->status = ForumPostStatus::REMOVED_BY_MODERATOR;
        $this->updatedAt = $now;
    }

    public function advanceVersion(): void
    {
        $this->version++;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function threadId(): Uuid
    {
        return $this->threadId;
    }

    public function authorId(): Uuid
    {
        return $this->authorId;
    }

    public function parentId(): ?Uuid
    {
        return $this->parentId;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): ForumPostStatus
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

    public function version(): int
    {
        return $this->version;
    }
}
