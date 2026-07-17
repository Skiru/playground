<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class SpecialOpeningIntervalInput
{
    public function __construct(public int $sequence, public string $opensAt, public string $closesAt, public bool $closesNextDay)
    {
        if ($sequence < 1) {
            throw new \InvalidArgumentException('Special opening interval sequence must be positive.');
        }
    }
}
