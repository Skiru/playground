<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine;

use App\Discovery\Application\PlaceReadModel;
use App\Discovery\Application\PlaceSearchQuery;
use App\Shared\Application\Clock;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PlaceReadRepository implements PlaceReadModel
{
    public function __construct(private Connection $connection, private Clock $clock)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(PlaceSearchQuery $query): array
    {
        [$where, $parameters, $types] = $this->filters($query);
        $distance = null !== $query->latitude
            ? 'ST_Distance(p.location, ST_SetSRID(ST_MakePoint(:longitude, :latitude), 4326)::geography)'
            : 'NULL';
        $score = '(CASE WHEN p.verification_status = \'admin_verified\' THEN 20 ELSE 0 END + CASE WHEN p.verified_at > :recentCutoff THEN 10 ELSE 0 END + 10'.(null !== $query->latitude ? ' + GREATEST(0, 10 - ('.$distance.' / 1000.0))' : '').')';
        $order = match ($query->sort) {
            'distance' => 'distance_meters ASC NULLS LAST, p.id ASC',
            'name' => 'p.normalized_name ASC, p.id ASC',
            'recentlyVerified' => 'p.verified_at DESC NULLS LAST, p.id ASC',
            default => 'relevance_score DESC, p.id ASC',
        };
        $parameters['recentCutoff'] = $this->clock->now()->modify('-180 days')->format(\DateTimeInterface::ATOM);
        $parameters['limit'] = $query->pageSize;
        $parameters['offset'] = ($query->page - 1) * $query->pageSize;

        $sql = 'SELECT p.id, p.slug, p.name, p.short_description, c.name AS city,
                COALESCE((SELECT json_agg(json_build_object(\'slug\', cat.slug, \'name\', cat.name) ORDER BY cat.display_order) FROM place_categories pc JOIN categories cat ON cat.id = pc.category_id WHERE pc.place_id = p.id), \'[]\'::json) AS categories,
                (SELECT MIN(min_age_months) FROM place_age_zones paz WHERE paz.place_id = p.id) AS min_age_months,
                (SELECT MAX(max_age_months) FROM place_age_zones paz WHERE paz.place_id = p.id) AS max_age_months,
                p.indoor, p.outdoor, p.free_entry, p.verification_status,
                COALESCE((SELECT json_agg(json_build_object(\'slug\', a.slug, \'name\', a.name) ORDER BY a.display_order) FROM (SELECT a.* FROM place_amenities pa JOIN amenities a ON a.id = pa.amenity_id WHERE pa.place_id = p.id ORDER BY a.display_order LIMIT 5) a), \'[]\'::json) AS amenities,
                '.$distance.' AS distance_meters, p.longitude, p.latitude,
                '.self::openExpression().' AS is_open_now,
                true AS complete, '.$score.' AS relevance_score
            FROM places p JOIN cities c ON c.id = p.city_id
            WHERE '.implode(' AND ', $where).'
            ORDER BY '.$order.' LIMIT :limit OFFSET :offset';
        $items = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        $countParameters = $parameters;
        unset($countParameters['limit'], $countParameters['offset'], $countParameters['recentCutoff']);
        if (!$query->openNow) {
            unset($countParameters['now']);
        }
        if (null === $query->radiusKm) {
            unset($countParameters['latitude'], $countParameters['longitude']);
        }
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM places p JOIN cities c ON c.id = p.city_id WHERE '.implode(' AND ', $where), $countParameters, $types);

        return ['items' => array_map(self::normalizeRow(...), $items), 'total' => $count];
    }

    /** @return list<array<string, mixed>> */
    public function referenceData(string $table): array
    {
        if (!\in_array($table, ['cities', 'categories', 'amenities'], true)) {
            throw new \InvalidArgumentException('Unsupported reference collection.');
        }
        $columns = 'cities' === $table ? 'id, name, slug, country_code, default_zoom, default_radius_km, timezone' : 'id, name, slug, icon_key, display_order';

        return $this->connection->fetchAllAssociative('SELECT '.$columns.' FROM '.$table.' WHERE enabled = true ORDER BY '.('cities' === $table ? 'name' : 'display_order, name'));
    }

    /** @return array<string, mixed>|null */
    public function details(string $slug): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT p.*, c.name AS city_name, c.slug AS city_slug,
            COALESCE((SELECT json_agg(json_build_object(\'slug\', cat.slug, \'name\', cat.name) ORDER BY cat.display_order) FROM place_categories pc JOIN categories cat ON cat.id=pc.category_id WHERE pc.place_id=p.id), \'[]\'::json) categories,
            COALESCE((SELECT json_agg(json_build_object(\'slug\', a.slug, \'name\', a.name) ORDER BY a.display_order) FROM place_amenities pa JOIN amenities a ON a.id=pa.amenity_id WHERE pa.place_id=p.id), \'[]\'::json) amenities,
            COALESCE((SELECT json_agg(json_build_object(\'name\', z.name, \'minAgeMonths\', z.min_age_months, \'maxAgeMonths\', z.max_age_months, \'notes\', z.notes) ORDER BY z.min_age_months) FROM place_age_zones z WHERE z.place_id=p.id), \'[]\'::json) age_zones,
            COALESCE((SELECT json_agg(json_build_object(\'weekday\', w.weekday, \'sequence\', w.sequence, \'opensAt\', w.opens_at, \'closesAt\', w.closes_at, \'closesNextDay\', w.closes_next_day) ORDER BY w.weekday,w.sequence) FROM weekly_opening_intervals w WHERE w.place_id=p.id), \'[]\'::json) weekly_opening,
            COALESCE((SELECT json_agg(json_build_object(\'localDate\', s.local_date, \'closed\', s.closed, \'note\', s.note) ORDER BY s.local_date) FROM special_opening_days s WHERE s.place_id=p.id), \'[]\'::json) special_opening
            FROM places p JOIN cities c ON c.id=p.city_id WHERE p.slug=:slug AND p.status=\'published\'', ['slug' => $slug]);

        return false === $row ? null : self::normalizeRow($row);
    }

    /** @return array{features: list<array<string, mixed>>, truncated: bool} */
    public function map(float $west, float $south, float $east, float $north, PlaceSearchQuery $query): array
    {
        [$where, $parameters, $types] = $this->filters($query);
        if (null === $query->radiusKm) {
            unset($parameters['latitude'], $parameters['longitude']);
        }
        $where[] = 'ST_Intersects(p.location, ST_MakeEnvelope(:west,:south,:east,:north,4326)::geography)';
        $parameters += ['west' => $west, 'south' => $south, 'east' => $east, 'north' => $north];
        $rows = $this->connection->fetchAllAssociative('SELECT p.id,p.slug,p.name,p.longitude,p.latitude,p.indoor,p.outdoor,p.free_entry FROM places p JOIN cities c ON c.id=p.city_id WHERE '.implode(' AND ', $where).' ORDER BY p.id LIMIT 501', $parameters, $types);
        $truncated = \count($rows) > 500;
        $features = array_map(static fn (array $row): array => ['type' => 'Feature', 'id' => $row['id'], 'geometry' => ['type' => 'Point', 'coordinates' => [(float) $row['longitude'], (float) $row['latitude']]], 'properties' => ['slug' => $row['slug'], 'name' => $row['name'], 'indoor' => (bool) $row['indoor'], 'outdoor' => (bool) $row['outdoor'], 'freeEntry' => (bool) $row['free_entry']]], \array_slice($rows, 0, 500));

        return ['features' => $features, 'truncated' => $truncated];
    }

    /** @return array{list<string>, array<string, mixed>, array<string, mixed>} */
    private function filters(PlaceSearchQuery $query): array
    {
        $where = ['p.status = \'published\''];
        $parameters = ['now' => $this->clock->now()->format(\DateTimeInterface::ATOM)];
        $types = [];
        if (null !== $query->city) {
            $where[] = 'c.slug = :city';
            $parameters['city'] = $query->city;
        }
        if (null !== $query->category) {
            $where[] = 'EXISTS (SELECT 1 FROM place_categories pc JOIN categories cat ON cat.id=pc.category_id WHERE pc.place_id=p.id AND cat.slug=:category)';
            $parameters['category'] = $query->category;
        }
        if (null !== $query->ageMonths) {
            $where[] = 'EXISTS (SELECT 1 FROM place_age_zones az WHERE az.place_id=p.id AND az.min_age_months<=:age AND (az.max_age_months IS NULL OR az.max_age_months>=:age))';
            $parameters['age'] = $query->ageMonths;
        }
        if ([] !== $query->amenities) {
            $where[] = '(SELECT COUNT(DISTINCT a.slug) FROM place_amenities pa JOIN amenities a ON a.id=pa.amenity_id WHERE pa.place_id=p.id AND a.slug IN (:amenities)) = :amenityCount';
            $parameters['amenities'] = $query->amenities;
            $parameters['amenityCount'] = \count($query->amenities);
            $types['amenities'] = ArrayParameterType::STRING;
        }
        foreach (['indoor' => $query->indoor, 'outdoor' => $query->outdoor, 'free_entry' => $query->freeEntry] as $column => $value) {
            if (null !== $value) {
                $key = str_replace('_', '', $column);
                $where[] = 'p.'.$column.' = :'.$key;
                $parameters[$key] = $value;
                $types[$key] = ParameterType::BOOLEAN;
            }
        }
        if (null !== $query->q) {
            $where[] = 'p.normalized_name ILIKE :q';
            $parameters['q'] = '%'.mb_strtolower($query->q).'%';
        }
        if (null !== $query->latitude) {
            $parameters['latitude'] = $query->latitude;
            $parameters['longitude'] = $query->longitude;
            if (null !== $query->radiusKm) {
                $where[] = 'ST_DWithin(p.location, ST_SetSRID(ST_MakePoint(:longitude,:latitude),4326)::geography, :radiusMeters)';
                $parameters['radiusMeters'] = $query->radiusKm * 1000;
            }
        }
        if ($query->openNow) {
            $where[] = self::openExpression();
        }

        return [$where, $parameters, $types];
    }

    private static function openExpression(): string
    {
        return '(EXISTS (SELECT 1 FROM special_opening_days sd WHERE sd.place_id=p.id AND sd.local_date=(CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::date AND sd.closed=false AND EXISTS (SELECT 1 FROM special_opening_intervals si WHERE si.special_opening_day_id=sd.id AND ((si.closes_next_day=false AND (CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time BETWEEN si.opens_at AND si.closes_at) OR (si.closes_next_day=true AND ((CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time>=si.opens_at OR (CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time<=si.closes_at))))) OR (NOT EXISTS (SELECT 1 FROM special_opening_days sd WHERE sd.place_id=p.id AND sd.local_date=(CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::date) AND EXISTS (SELECT 1 FROM weekly_opening_intervals wi WHERE wi.place_id=p.id AND ((wi.weekday=EXTRACT(ISODOW FROM (CAST(:now AS timestamptz) AT TIME ZONE p.timezone)) AND ((wi.closes_next_day=false AND (CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time BETWEEN wi.opens_at AND wi.closes_at) OR (wi.closes_next_day=true AND (CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time>=wi.opens_at))) OR (wi.closes_next_day=true AND wi.weekday=CASE WHEN EXTRACT(ISODOW FROM (CAST(:now AS timestamptz) AT TIME ZONE p.timezone))=1 THEN 7 ELSE EXTRACT(ISODOW FROM (CAST(:now AS timestamptz) AT TIME ZONE p.timezone))-1 END AND (CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time<=wi.closes_at)))))';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        foreach (['categories', 'amenities', 'age_zones', 'weekly_opening', 'special_opening'] as $key) {
            if (isset($row[$key]) && \is_string($row[$key])) {
                $row[$key] = json_decode($row[$key], true, flags: \JSON_THROW_ON_ERROR);
            }
        }
        foreach (['latitude', 'longitude', 'distance_meters', 'relevance_score'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (float) $row[$key];
            }
        }

        return $row;
    }
}
