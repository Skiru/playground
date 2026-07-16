<?php

declare(strict_types=1);

namespace App\Places\Domain;

final readonly class OpeningScheduleEvaluation
{
    public function __construct(
        public OpeningState $state,
        public string $reason,
        public string $source,
        public ?\DateTimeImmutable $nextTransition,
    ) {
    }
}
