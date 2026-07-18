<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class PlacePhoto
{
    private Uuid $id;
    private PlacePhotoStatus $status;
    private bool $isMain = false;
    private int $displayOrder = 0;
    private ?string $altText = null;
    private ?string $caption = null;
    /** @var array<string, string>|null */
    private ?array $variants = null;
    private int $processingGeneration = 1;
    private ?string $failureCode = null;
    private ?\DateTimeImmutable $processedAt = null;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private readonly Place $place,
        private readonly string $originalFilename,
        private readonly string $filePath,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->status = PlacePhotoStatus::QUEUED;
        $this->updatedAt = $createdAt;
    }

    /**
     * @param array<string, string>|null $variants
     */
    public static function reconstitute(
        Uuid $id,
        Place $place,
        string $originalFilename,
        string $filePath,
        PlacePhotoStatus $status,
        bool $isMain,
        int $displayOrder,
        ?string $altText,
        ?string $caption,
        ?array $variants,
        int $processingGeneration,
        ?string $failureCode,
        ?\DateTimeImmutable $processedAt,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ): self {
        $photo = new self($place, $originalFilename, $filePath, $createdAt);
        $photo->id = $id;
        $photo->status = $status;
        $photo->isMain = $isMain;
        $photo->displayOrder = $displayOrder;
        $photo->altText = $altText;
        $photo->caption = $caption;
        $photo->variants = $variants;
        $photo->processingGeneration = $processingGeneration;
        $photo->failureCode = $failureCode;
        $photo->processedAt = $processedAt;
        $photo->updatedAt = $updatedAt;

        return $photo;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function place(): Place
    {
        return $this->place;
    }

    public function originalFilename(): string
    {
        return $this->originalFilename;
    }

    public function filePath(): string
    {
        return $this->filePath;
    }

    public function status(): PlacePhotoStatus
    {
        return $this->status;
    }

    public function isMain(): bool
    {
        return $this->isMain;
    }

    public function displayOrder(): int
    {
        return $this->displayOrder;
    }

    public function altText(): ?string
    {
        return $this->altText;
    }

    public function caption(): ?string
    {
        return $this->caption;
    }

    /** @return array<string, string>|null */
    public function variants(): ?array
    {
        return $this->variants;
    }

    public function processingGeneration(): int
    {
        return $this->processingGeneration;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function startProcessing(int $generation, \DateTimeImmutable $now): void
    {
        $this->status = PlacePhotoStatus::PROCESSING;
        $this->processingGeneration = $generation;
        $this->updatedAt = $now;
    }

    /** @param array<string, string> $variants */
    public function markCompleted(int $generation, array $variants, \DateTimeImmutable $now): void
    {
        $this->status = PlacePhotoStatus::COMPLETED;
        $this->processingGeneration = $generation;
        $this->variants = $variants;
        $this->processedAt = $now;
        $this->updatedAt = $now;
        $this->failureCode = null;
    }

    public function markFailed(int $generation, string $failureCode, \DateTimeImmutable $now): void
    {
        $this->status = PlacePhotoStatus::FAILED;
        $this->processingGeneration = $generation;
        $this->failureCode = $failureCode;
        $this->updatedAt = $now;
    }

    public function markDeleting(\DateTimeImmutable $now): void
    {
        $this->status = PlacePhotoStatus::DELETING;
        $this->updatedAt = $now;
    }

    public function incrementGeneration(\DateTimeImmutable $now): void
    {
        $this->status = PlacePhotoStatus::QUEUED;
        $this->processingGeneration++;
        $this->failureCode = null;
        $this->updatedAt = $now;
    }

    public function updateDetails(?string $altText, ?string $caption, int $displayOrder, \DateTimeImmutable $updatedAt): void
    {
        $this->altText = $altText;
        $this->caption = $caption;
        $this->displayOrder = $displayOrder;
        $this->updatedAt = $updatedAt;
    }

    public function setMain(bool $isMain, \DateTimeImmutable $updatedAt): void
    {
        $this->isMain = $isMain;
        $this->updatedAt = $updatedAt;
    }
}
