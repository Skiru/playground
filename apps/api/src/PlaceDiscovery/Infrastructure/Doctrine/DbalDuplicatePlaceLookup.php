<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Infrastructure\Doctrine;

use App\PlaceDiscovery\Application\Port\DuplicatePlaceLookup;
use App\PlaceDiscovery\Domain\DuplicateAssessment;
use App\PlaceDiscovery\Domain\DuplicateDetector;
use App\PlaceDiscovery\Domain\NormalizedPlace;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use Doctrine\DBAL\Connection;

final readonly class DbalDuplicatePlaceLookup implements DuplicatePlaceLookup
{
    public function __construct(private Connection $connection, private DuplicateDetector $distance, private PlaceNormalizer $normalizer)
    {
    }

    public function assess(ProviderPlace $source, NormalizedPlace $normalized): DuplicateAssessment
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT p.id, p.name, p.website_url, p.phone, p.address_line1, p.latitude, p.longitude, city.name AS locality
FROM places p JOIN cities city ON city.id = p.city_id
WHERE ((p.latitude BETWEEN ? AND ?) AND (p.longitude BETWEEN ? AND ?))
   OR (? IS NOT NULL AND regexp_replace(COALESCE(p.phone, ''), '[^0-9+]', '', 'g') = ?)
   OR lower(p.normalized_name) = ?
LIMIT 100
SQL, [$source->latitude - 0.03, $source->latitude + 0.03, $source->longitude - 0.05, $source->longitude + 0.05, $normalized->phone, $normalized->phone, $normalized->normalizedName]);
        $bestScore = 0;
        $bestReasons = [];
        $ids = [];
        foreach ($rows as $row) {
            $score = 0;
            $reasons = [];
            $existingName = $this->normalizer->comparison((string) $row['name']);
            if ($existingName === $normalized->normalizedName) {
                $score += 45;
                $reasons[] = 'exact_normalized_name';
            } elseif (levenshtein($existingName, $normalized->normalizedName) <= 3) {
                $score += 25;
                $reasons[] = 'similar_normalized_name';
            }
            if (null !== $normalized->phone && $normalized->phone === preg_replace('/(?!^\+)\D+/', '', (string) $row['phone'])) {
                $score += 70;
                $reasons[] = 'normalized_phone';
            }
            if (null !== $normalized->websiteHost && str_contains(mb_strtolower((string) $row['website_url']), $normalized->websiteHost)) {
                $score += 70;
                $reasons[] = 'website_host';
            }
            if (null !== $source->locality && $this->normalizer->comparison((string) $row['locality']) === $this->normalizer->comparison($source->locality)) {
                $score += 10;
                $reasons[] = 'same_locality';
            }
            $metres = $this->distance->haversineMetres($source->latitude, $source->longitude, (float) $row['latitude'], (float) $row['longitude']);
            if ($metres <= 150) {
                $score += 30;
                $reasons[] = 'within_150m';
            } elseif ($metres <= 500) {
                $score += 15;
                $reasons[] = 'within_500m';
            }
            $score = min(100, $score);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestReasons = $reasons;
                $ids = [(string) $row['id']];
            } elseif ($score === $bestScore && $score > 0) {
                $ids[] = (string) $row['id'];
            }
        }

        return new DuplicateAssessment($bestScore, $bestReasons, $ids);
    }
}
