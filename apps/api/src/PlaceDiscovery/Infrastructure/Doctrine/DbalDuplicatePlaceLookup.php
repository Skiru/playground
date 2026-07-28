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
SELECT p.id, 'place' AS kind, p.name, p.website_url, p.phone, p.address_line1, p.latitude, p.longitude, city.name AS locality
FROM places p JOIN cities city ON city.id = p.city_id
WHERE ((p.latitude BETWEEN ? AND ?) AND (p.longitude BETWEEN ? AND ?))
   OR (CAST(? AS VARCHAR) IS NOT NULL AND regexp_replace(COALESCE(p.phone, ''), '[^0-9+]', '', 'g') = ?)
   OR lower(p.normalized_name) = ?
ORDER BY ((p.latitude - ?) * (p.latitude - ?) + (p.longitude - ?) * (p.longitude - ?)), p.id
LIMIT 500
SQL, [$source->latitude - 0.03, $source->latitude + 0.03, $source->longitude - 0.05, $source->longitude + 0.05, $normalized->phone, $normalized->phone, $normalized->normalizedName, $source->latitude, $source->latitude, $source->longitude, $source->longitude]);
        $rows = [...$rows, ...$this->connection->fetchAllAssociative(<<<'SQL'
SELECT c.id, 'candidate' AS kind, c.name, c.website AS website_url, c.phone, c.address_line1, c.latitude, c.longitude, c.locality
FROM place_candidates c
WHERE c.source = ? AND c.external_id <> ? AND c.status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE')
  AND (((c.latitude BETWEEN ? AND ?) AND (c.longitude BETWEEN ? AND ?))
    OR (CAST(? AS VARCHAR) IS NOT NULL AND c.normalized_phone = ?)
    OR c.normalized_name = ?)
ORDER BY ((c.latitude - ?) * (c.latitude - ?) + (c.longitude - ?) * (c.longitude - ?)), c.id
LIMIT 500
SQL, ['overture', $source->externalId, $source->latitude - 0.03, $source->latitude + 0.03, $source->longitude - 0.05, $source->longitude + 0.05, $normalized->phone, $normalized->phone, $normalized->normalizedName, $source->latitude, $source->latitude, $source->longitude, $source->longitude])];
        $matches = [];
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
            $existingHost = parse_url(str_contains((string) $row['website_url'], '://') ? (string) $row['website_url'] : 'https://'.$row['website_url'], \PHP_URL_HOST);
            $existingHost = \is_string($existingHost) ? preg_replace('/^www\./i', '', mb_strtolower($existingHost)) : null;
            if (null !== $normalized->websiteHost && $existingHost === $normalized->websiteHost) {
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
            if ($score > 0) {
                $matches[] = ['id' => (string) $row['id'], 'kind' => (string) $row['kind'], 'score' => $score, 'reasons' => $reasons, 'metres' => $metres];
            }
        }
        usort($matches, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score']) ?: ($left['metres'] <=> $right['metres']) ?: ($left['kind'] <=> $right['kind']) ?: ($left['id'] <=> $right['id']);
        });
        $matches = \array_slice($matches, 0, 10);
        $best = $matches[0] ?? null;
        $places = [];
        $candidates = [];
        foreach ($matches as $match) {
            if ('place' === $match['kind']) {
                $places[] = $match['id'];
            } else {
                $candidates[] = $match['id'];
            }
        }

        return new DuplicateAssessment($best['score'] ?? 0, $best['reasons'] ?? [], $places, $candidates);
    }
}
