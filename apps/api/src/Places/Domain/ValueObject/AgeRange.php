<?php

declare(strict_types=1);

namespace App\Places\Domain\ValueObject;

final readonly class AgeRange
{
    public function __construct(public int $minAgeMonths, public ?int $maxAgeMonths)
    {
        if ($minAgeMonths < 0 || $minAgeMonths > 216) {
            throw new \InvalidArgumentException('Minimum age must be between 0 and 216 months.');
        }
        if (null !== $maxAgeMonths && ($maxAgeMonths < $minAgeMonths || $maxAgeMonths > 216)) {
            throw new \InvalidArgumentException('Maximum age must be between minimum age and 216 months.');
        }
    }

    public function includes(int $ageMonths): bool
    {
        return $ageMonths >= $this->minAgeMonths && (null === $this->maxAgeMonths || $ageMonths <= $this->maxAgeMonths);
    }
}
