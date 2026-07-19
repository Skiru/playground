<?php

declare(strict_types=1);

namespace App\Community\Domain\Review;

use Symfony\Component\Uid\Uuid;

final class Review
{
    private Uuid $id;
    private Uuid $placeId;
    private Uuid $authorId;
    private int $rating;
    private string $body;
    private ?\DateTimeImmutable $visitedOn;
    private ReviewStatus $status;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version;

    public function __construct(
        Uuid $id,
        Uuid $placeId,
        Uuid $authorId,
        int $rating,
        string $body,
        ?\DateTimeImmutable $visitedOn,
        ReviewStatus $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version = 1
    ) {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }
        $trimmedBody = trim($body);
        if (mb_strlen($trimmedBody) < 20 || mb_strlen($trimmedBody) > 5000) {
            throw new \InvalidArgumentException('Review body must be between 20 and 5000 characters.');
        }

        $this->id = $id;
        $this->placeId = $placeId;
        $this->authorId = $authorId;
        $this->rating = $rating;
        $this->body = $trimmedBody;
        $this->visitedOn = $visitedOn;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->version = $version;
    }

    public function edit(int $rating, string $body, ?\DateTimeImmutable $visitedOn, \DateTimeImmutable $now): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }
        $trimmedBody = trim($body);
        if (mb_strlen($trimmedBody) < 20 || mb_strlen($trimmedBody) > 5000) {
            throw new \InvalidArgumentException('Review body must be between 20 and 5000 characters.');
        }

        $this->rating = $rating;
        $this->body = $trimmedBody;
        $this->visitedOn = $visitedOn;
        $this->updatedAt = $now;
    }

    public function softDelete(\DateTimeImmutable $now): void
    {
        $this->status = ReviewStatus::DELETED_BY_AUTHOR;
        $this->updatedAt = $now;
    }

    public function hide(\DateTimeImmutable $now): void
    {
        $this->status = ReviewStatus::HIDDEN;
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $this->status = ReviewStatus::PUBLISHED;
        $this->updatedAt = $now;
    }

    public function removeByModerator(\DateTimeImmutable $now): void
    {
        $this->status = ReviewStatus::REMOVED_BY_MODERATOR;
        $this->updatedAt = $now;
    }

    // Getters
    public function id(): Uuid { return $this->id; }
    public function placeId(): Uuid { return $this->placeId; }
    public function authorId(): Uuid { return $this->authorId; }
    public function rating(): int { return $this->rating; }
    public function body(): string { return $this->body; }
    public function visitedOn(): ?\DateTimeImmutable { return $this->visitedOn; }
    public function status(): ReviewStatus { return $this->status; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function version(): int { return $this->version; }
}
