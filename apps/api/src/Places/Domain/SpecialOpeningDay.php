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
        private SpecialOpeningDayMode $mode,
        private ?string $note = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function addInterval(SpecialOpeningInterval $interval): void
    {
        if (SpecialOpeningDayMode::CUSTOM !== $this->mode) {
            throw new \DomainException('Only a custom special day can have intervals.');
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
        return SpecialOpeningDayMode::CLOSED === $this->mode;
    }

    public function mode(): SpecialOpeningDayMode
    {
        return $this->mode;
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

    public function assertValid(): void
    {
        if (SpecialOpeningDayMode::CUSTOM === $this->mode ? [] === $this->intervals : [] !== $this->intervals) {
            throw new \InvalidArgumentException('Special opening day mode and intervals are inconsistent.');
        }

        $sequences = array_map(static fn (SpecialOpeningInterval $interval): int => $interval->sequence(), $this->intervals);
        sort($sequences);
        if ($sequences !== ([] === $sequences ? [] : range(1, \count($sequences)))) {
            throw new \InvalidArgumentException('Special opening interval sequence must be contiguous.');
        }
    }
}
