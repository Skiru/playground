<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class SpecialOpeningInterval
{
    private Uuid $id;

    public function __construct(
        private SpecialOpeningDay $specialOpeningDay,
        private int $sequence,
        private \DateTimeImmutable $opensAt,
        private \DateTimeImmutable $closesAt,
        private bool $closesNextDay,
    ) {
        if ($sequence < 1 || (!$closesNextDay && $closesAt <= $opensAt) || ($closesNextDay && $closesAt > $opensAt)) {
            throw new \InvalidArgumentException('Invalid special opening interval.');
        }
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function specialOpeningDay(): SpecialOpeningDay
    {
        return $this->specialOpeningDay;
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
