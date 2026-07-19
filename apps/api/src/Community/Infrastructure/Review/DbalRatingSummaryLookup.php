<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Review;

use App\Community\Application\Review\RatingSummaryLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalRatingSummaryLookup implements RatingSummaryLookup
{
    public function __construct(private Connection $connection)
    {
    }

    public function getSummary(Uuid $placeId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT 
                COALESCE(AVG(rating), 0.0) as average_rating,
                COUNT(*) as total_reviews,
                COALESCE(SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END), 0) as r1,
                COALESCE(SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END), 0) as r2,
                COALESCE(SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END), 0) as r3,
                COALESCE(SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END), 0) as r4,
                COALESCE(SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END), 0) as r5
            FROM reviews 
            WHERE place_id = :place_id AND status = 'PUBLISHED'",
            ['place_id' => $placeId->toRfc4122()]
        );

        if (false === $row) {
            return [
                'averageRating' => 0.0,
                'totalReviews' => 0,
                'histogram' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            ];
        }

        return [
            'averageRating' => round((float) $row['average_rating'], 2),
            'totalReviews' => (int) $row['total_reviews'],
            'histogram' => [
                1 => (int) $row['r1'],
                2 => (int) $row['r2'],
                3 => (int) $row['r3'],
                4 => (int) $row['r4'],
                5 => (int) $row['r5'],
            ],
        ];
    }
}
