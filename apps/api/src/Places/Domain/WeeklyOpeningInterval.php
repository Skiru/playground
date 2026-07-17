<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class WeeklyOpeningInterval
{
    private Uuid $id;

    public function __construct(
        private Place $place,
        private int $weekday,
        private int $sequence,
        private \DateTimeImmutable $opensAt,
        private \DateTimeImmutable $closesAt,
        private bool $closesNextDay,
    ) {
        if ($weekday < 1 || $weekday > 7 || $sequence < 1) {
            throw new \InvalidArgumentException('Invalid weekly opening interval.');
        }
        if ((!$closesNextDay && $closesAt <= $opensAt) || ($closesNextDay && $closesAt > $opensAt)) {
            throw new \InvalidArgumentException('Closing time and next-day marker are inconsistent.');
        }
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function place(): Place
    {
        return $this->place;
    }

    public function weekday(): int
    {
        return $this->weekday;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function opensAt(): \DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function closesAt(): \DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function closesNextDay(): bool
    {
        return $this->closesNextDay;
    }
}
