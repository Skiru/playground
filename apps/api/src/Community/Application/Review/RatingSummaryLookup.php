<?php

declare(strict_types=1);

namespace App\Community\Application\Review;

use Symfony\Component\Uid\Uuid;

interface RatingSummaryLookup
{
    /** @return array{averageRating: float, totalReviews: int, histogram: array<int, int>} */
    public function getSummary(Uuid $placeId): array;
}
