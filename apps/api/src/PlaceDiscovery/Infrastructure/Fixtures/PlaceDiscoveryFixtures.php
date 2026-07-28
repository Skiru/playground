<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Infrastructure\Fixtures;

use App\Places\Infrastructure\Fixtures\PlacesFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

final class PlaceDiscoveryFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getDependencies(): array
    {
        return [PlacesFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $now = '2026-07-28 09:30:00+02';
        $areaId = self::id(900);
        $runId = self::id(901);
        $this->connection->insert('place_discovery_areas', ['id' => $areaId, 'name' => 'Rzeszów - pilot 3 km', 'enabled' => 'false', 'country_code' => 'PL', 'center_latitude' => 50.0413, 'center_longitude' => 21.999, 'radius_km' => 3, 'bbox_west' => 21.957, 'bbox_south' => 50.014, 'bbox_east' => 22.041, 'bbox_north' => 50.068, 'minimum_confidence' => 0.8, 'maximum_candidates_per_run' => 20, 'discovery_profile' => 'family-v1', 'last_successful_release' => '2026-07-22.0', 'created_at' => $now, 'updated_at' => $now]);
        $this->connection->insert('place_discovery_runs', ['id' => $runId, 'source' => 'overture', 'source_release' => '2026-07-22.0', 'area_id' => $areaId, 'attempt' => 1, 'status' => 'COMPLETED', 'started_at' => $now, 'completed_at' => $now, 'requested_by' => 'fixture', 'discovered_count' => 4, 'inserted_count' => 4, 'duplicate_count' => 1, 'created_at' => $now]);
        $categoryId = (string) $this->connection->fetchOne("SELECT id FROM categories WHERE slug = 'bawialnie'");
        $rows = [
            [902, 'gers-normal', 'Bawialnia Dziecięca Rzeszów', 'bawialnia dziecieca rzeszow', 'PENDING', $categoryId, null, null],
            [903, 'gers-unmapped', 'Centrum Aktywności', 'centrum aktywnosci', 'NEEDS_MAPPING', null, null, null],
            [904, 'gers-duplicate', 'Demo Bawialnia Mokotów', 'demo bawialnia mokotow', 'POSSIBLE_DUPLICATE', $categoryId, self::id(400), null],
            [905, 'gers-rejected', 'Nieaktualny plac zabaw', 'nieaktualny plac zabaw', 'REJECTED', $categoryId, null, 'Nie jest miejscem dostępnym publicznie.'],
        ];
        foreach ($rows as [$number, $externalId, $name, $normalized, $status, $category, $duplicatePlace, $reason]) {
            $provenance = json_encode([['property' => '', 'dataset' => 'Overture Maps Foundation', 'license' => 'CDLA-Permissive-2.0', 'record_id' => $externalId, 'update_time' => $now]], \JSON_THROW_ON_ERROR);
            $snapshot = json_encode(['id' => $externalId, 'name' => $name, 'basic_category' => 'playground', 'taxonomy' => ['hierarchy' => ['playground']], 'confidence' => 0.91, 'operating_status' => 'open', 'sources' => json_decode($provenance, true, 8, \JSON_THROW_ON_ERROR)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $this->connection->insert('place_candidates', ['id' => self::id($number), 'discovery_run_id' => $runId, 'source' => 'overture', 'external_id' => $externalId, 'source_release' => '2026-07-22.0', 'source_record_version' => '1', 'source_payload_hash' => hash('sha256', $snapshot), 'source_snapshot' => $snapshot, 'source_provenance' => $provenance, 'name' => $name, 'normalized_name' => $normalized, 'address_line1' => 'Rynek '.($number - 900), 'postal_code' => '35-001', 'locality' => 'Rzeszów', 'country_code' => 'PL', 'latitude' => 50.0413 + (($number - 902) * 0.001), 'longitude' => 21.999, 'source_categories' => json_encode(['playground'], \JSON_THROW_ON_ERROR), 'suggested_place_category_id' => $category, 'suggested_city_id' => self::id(105), 'city_selection_source' => 'AUTO', 'indoor' => 'true', 'outdoor' => 'false', 'free_entry' => 'false', 'confidence' => 0.91, 'operating_status' => 'open', 'discovery_score' => 83, 'discovery_reasons' => json_encode(['family_category:playground'], \JSON_THROW_ON_ERROR), 'duplicate_score' => null === $duplicatePlace ? null : 90, 'duplicate_reasons' => null === $duplicatePlace ? null : json_encode(['exact_normalized_name'], \JSON_THROW_ON_ERROR), 'possible_duplicate_place_id' => $duplicatePlace, 'status' => $status, 'first_seen_at' => $now, 'last_seen_at' => $now, 'reviewed_by' => null === $reason ? null : 'fixture-admin', 'reviewed_at' => null === $reason ? null : $now, 'rejection_reason' => $reason, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-7000-8000-%012d', $number);
    }
}
