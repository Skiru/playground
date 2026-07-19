<?php

declare(strict_types=1);

namespace App\Community\Domain\PlaceDiscussion;

use Symfony\Component\Uid\Uuid;

final class PlaceComment
{
    private Uuid $id;
    private Uuid $placeId;
    private Uuid $authorId;
    private ?Uuid $parentId;
    private string $body;
    private PlaceCommentStatus $status;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version;

    public function __construct(
        Uuid $id,
        Uuid $placeId,
        Uuid $authorId,
        ?Uuid $parentId,
        string $body,
        PlaceCommentStatus $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version = 1
    ) {
        $trimmedBody = trim($body);
        if (mb_strlen($trimmedBody) < 1 || mb_strlen($trimmedBody) > 3000) {
            throw new \InvalidArgumentException('Comment body must be between 1 and 3000 characters.');
        }

        $this->id = $id;
        $this->placeId = $placeId;
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
        $trimmedBody = trim($body);
        if (mb_strlen($trimmedBody) < 1 || mb_strlen($trimmedBody) > 3000) {
            throw new \InvalidArgumentException('Comment body must be between 1 and 3000 characters.');
        }

        $this->body = $trimmedBody;
        $this->updatedAt = $now;
    }

    public function softDelete(\DateTimeImmutable $now): void
    {
        $this->status = PlaceCommentStatus::DELETED_BY_AUTHOR;
        $this->updatedAt = $now;
    }

    public function hide(\DateTimeImmutable $now): void
    {
        $this->status = PlaceCommentStatus::HIDDEN;
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $this->status = PlaceCommentStatus::PUBLISHED;
        $this->updatedAt = $now;
    }

    public function removeByModerator(\DateTimeImmutable $now): void
    {
        $this->status = PlaceCommentStatus::REMOVED_BY_MODERATOR;
        $this->updatedAt = $now;
    }

    // Getters
    public function id(): Uuid { return $this->id; }
    public function placeId(): Uuid { return $this->placeId; }
    public function authorId(): Uuid { return $this->authorId; }
    public function parentId(): ?Uuid { return $this->parentId; }
    public function body(): string { return $this->body; }
    public function status(): PlaceCommentStatus { return $this->status; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function version(): int { return $this->version; }
}
