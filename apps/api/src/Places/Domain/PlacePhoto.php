<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class PlacePhoto
{
    private Uuid $id;
    private string $status = 'processing';
    private bool $isMain = false;
    private int $displayOrder = 0;
    private ?string $altText = null;
    private ?string $caption = null;
    /** @var array<string, string>|null */
    private ?array $variants = null;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private readonly Place $place,
        private readonly string $originalFilename,
        private readonly string $filePath,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
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
        string $status,
        bool $isMain,
        int $displayOrder,
        ?string $altText,
        ?string $caption,
        ?array $variants,
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

    public function status(): string
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<string, string> $variants */
    public function markCompleted(array $variants, \DateTimeImmutable $updatedAt): void
    {
        $this->status = 'completed';
        $this->variants = $variants;
        $this->updatedAt = $updatedAt;
    }

    public function markFailed(\DateTimeImmutable $updatedAt): void
    {
        $this->status = 'failed';
        $this->updatedAt = $updatedAt;
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
