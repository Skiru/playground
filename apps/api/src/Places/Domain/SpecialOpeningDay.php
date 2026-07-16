<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class SpecialOpeningDay
{
    private Uuid $id;
    /** @var list<SpecialOpeningInterval> */
    private array $intervals = [];

    public function __construct(
        private Place $place,
        private \DateTimeImmutable $localDate,
        private bool $closed,
        private ?string $note = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function addInterval(SpecialOpeningInterval $interval): void
    {
        if ($this->closed) {
            throw new \DomainException('A closed special day cannot have intervals.');
        }
        $this->intervals[] = $interval;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function place(): Place
    {
        return $this->place;
    }

    public function localDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /** @return list<SpecialOpeningInterval> */
    public function intervals(): array
    {
        return $this->intervals;
    }
}
