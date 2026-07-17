<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class SpecialOpeningDayInput
{
    /** @param list<SpecialOpeningIntervalInput> $intervals */
    public function __construct(public string $localDate, public SpecialOpeningDayModeInput $mode, public ?string $note, public array $intervals)
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $localDate);
        if (false === $date || $date->format('Y-m-d') !== $localDate) {
            throw new \InvalidArgumentException('Invalid special opening day.');
        }
        if (SpecialOpeningDayModeInput::CUSTOM === $mode ? [] === $intervals : [] !== $intervals) {
            throw new \InvalidArgumentException('Invalid special opening day.');
        }
    }
}
