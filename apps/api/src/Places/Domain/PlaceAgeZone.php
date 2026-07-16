<?php

declare(strict_types=1);

namespace App\Places\Domain;

use App\Places\Domain\ValueObject\AgeRange;
use Symfony\Component\Uid\Uuid;

final class PlaceAgeZone
{
    private Uuid $id;

    public function __construct(
        private Place $place,
        private string $name,
        private AgeRange $ageRange,
        private ?string $notes,
        private string $sourceType,
        private ?\DateTimeImmutable $verifiedAt = null,
    ) {
        if ('' === trim($name) || '' === trim($sourceType)) {
            throw new \InvalidArgumentException('Age zone name and source are required.');
        }
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function ageRange(): AgeRange
    {
        return $this->ageRange;
    }

    public function place(): Place
    {
        return $this->place;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function verifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }
}
