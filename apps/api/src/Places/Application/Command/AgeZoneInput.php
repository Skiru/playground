<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class AgeZoneInput
{
    public function __construct(public string $name, public int $minAgeMonths, public ?int $maxAgeMonths, public ?string $notes = null)
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Age zone name is required.');
        }
    }
}
