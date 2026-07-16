<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

final readonly class AgeSummary implements \JsonSerializable
{
    public function __construct(
        public int $minAgeMonths,
        public ?int $maxAgeMonths,
    ) {
    }

    /** @return array{min_age_months: int, max_age_months: ?int} */
    public function jsonSerialize(): array
    {
        return ['min_age_months' => $this->minAgeMonths, 'max_age_months' => $this->maxAgeMonths];
    }
}
