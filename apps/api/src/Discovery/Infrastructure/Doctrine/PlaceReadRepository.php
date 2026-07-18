<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine;

use App\Discovery\Application\Dto\AgeSummary;
use App\Discovery\Application\Dto\MapPlaceFeature;
use App\Discovery\Application\Dto\OpeningStatus;
use App\Discovery\Application\Dto\PlaceDetails;
use App\Discovery\Application\Dto\PlaceListItem;
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

    /** @return array{items: list<PlaceListItem>, total: int} */
    public function search(PlaceSearchQuery $query): array
    {
        $this->connection->executeStatement('SET statement_timeout TO 2000');
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
                true AS complete, '.$score.' AS relevance_score,
                (SELECT variants FROM place_photos WHERE place_id = p.id AND is_main = true AND status = \'COMPLETED\' LIMIT 1) AS main_photo_variants
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

        return ['items' => array_map(self::listItem(...), $items), 'total' => $count];
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

    public function details(string $slug): ?PlaceDetails
    {
        $row = $this->connection->fetchAssociative('SELECT p.id,p.slug,p.name,p.short_description,p.description,p.address_line1,p.address_line2,p.postal_code,p.country_code,p.indoor,p.outdoor,p.free_entry,p.price_description,p.website_url,p.phone,p.verification_status,p.longitude,p.latitude,c.name AS city_name,c.slug AS city_slug,
            COALESCE((SELECT json_agg(json_build_object(\'slug\', cat.slug, \'name\', cat.name) ORDER BY cat.display_order) FROM place_categories pc JOIN categories cat ON cat.id=pc.category_id WHERE pc.place_id=p.id), \'[]\'::json) categories,
            COALESCE((SELECT json_agg(json_build_object(\'slug\', a.slug, \'name\', a.name) ORDER BY a.display_order) FROM place_amenities pa JOIN amenities a ON a.id=pa.amenity_id WHERE pa.place_id=p.id), \'[]\'::json) amenities,
            COALESCE((SELECT json_agg(json_build_object(\'name\', z.name, \'minAgeMonths\', z.min_age_months, \'maxAgeMonths\', z.max_age_months, \'notes\', z.notes) ORDER BY z.min_age_months) FROM place_age_zones z WHERE z.place_id=p.id), \'[]\'::json) age_zones,
            COALESCE((SELECT json_agg(json_build_object(\'weekday\', w.weekday, \'sequence\', w.sequence, \'opensAt\', w.opens_at, \'closesAt\', w.closes_at, \'closesNextDay\', w.closes_next_day) ORDER BY w.weekday,w.sequence) FROM weekly_opening_intervals w WHERE w.place_id=p.id), \'[]\'::json) weekly_opening,
            COALESCE((SELECT json_agg(json_build_object(\'localDate\', s.local_date, \'closed\', s.mode=\'closed\', \'note\', s.note) ORDER BY s.local_date) FROM special_opening_days s WHERE s.place_id=p.id), \'[]\'::json) special_opening,
            (SELECT variants FROM place_photos WHERE place_id = p.id AND is_main = true AND status = \'COMPLETED\' LIMIT 1) AS main_photo_variants,
            COALESCE((SELECT json_agg(json_build_object(\'id\', id, \'is_main\', is_main, \'alt_text\', alt_text, \'caption\', caption, \'variants\', variants) ORDER BY display_order, id) FROM place_photos WHERE place_id = p.id AND status = \'COMPLETED\'), \'[]\'::json) AS photos
            FROM places p JOIN cities c ON c.id=p.city_id WHERE p.slug=:slug AND p.status=\'published\'', ['slug' => $slug]);

        return false === $row ? null : self::detailsItem($row);
    }

    /** @return array{features: list<MapPlaceFeature>, truncated: bool} */
    public function map(float $west, float $south, float $east, float $north, PlaceSearchQuery $query): array
    {
        $this->connection->executeStatement('SET statement_timeout TO 2000');
        [$where, $parameters, $types] = $this->filters($query);
        if (null === $query->radiusKm) {
            unset($parameters['latitude'], $parameters['longitude']);
        }
        $where[] = 'ST_Intersects(p.location, ST_MakeEnvelope(:west,:south,:east,:north,4326)::geography)';
        $parameters += ['west' => $west, 'south' => $south, 'east' => $east, 'north' => $north];
        $rows = $this->connection->fetchAllAssociative('SELECT p.id,p.slug,p.name,p.longitude,p.latitude,p.indoor,p.outdoor,p.free_entry FROM places p JOIN cities c ON c.id=p.city_id WHERE '.implode(' AND ', $where).' ORDER BY p.id LIMIT 501', $parameters, $types);
        $truncated = \count($rows) > 500;
        $features = array_map(static fn (array $row): MapPlaceFeature => new MapPlaceFeature('Feature', (string) $row['id'], ['type' => 'Point', 'coordinates' => [(float) $row['longitude'], (float) $row['latitude']]], ['slug' => (string) $row['slug'], 'name' => (string) $row['name'], 'indoor' => (bool) $row['indoor'], 'outdoor' => (bool) $row['outdoor'], 'freeEntry' => (bool) $row['free_entry']]), \array_slice($rows, 0, 500));

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
            $where[] = '(p.normalized_name ILIKE :q OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:q) OR to_tsvector(\'simple\', immutable_unaccent(p.short_description || \' \' || p.description)) @@ websearch_to_tsquery(\'simple\', immutable_unaccent(:searchQuery)))';
            $parameters['q'] = '%'.mb_strtolower($query->q).'%';
            $parameters['searchQuery'] = $query->q;
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
        $localDate = '(CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::date';
        $localTime = '(CAST(:now AS timestamptz) AT TIME ZONE p.timezone)::time';
        $weekday = 'EXTRACT(ISODOW FROM (CAST(:now AS timestamptz) AT TIME ZONE p.timezone))';
        $previousWeekday = 'CASE WHEN '.$weekday.'=1 THEN 7 ELSE '.$weekday.'-1 END';

        $currentSpecial = 'EXISTS (SELECT 1 FROM special_opening_days sd WHERE sd.place_id=p.id AND sd.local_date='.$localDate.')';
        $currentMode = static fn (string $mode): string => "EXISTS (SELECT 1 FROM special_opening_days sd WHERE sd.place_id=p.id AND sd.local_date={$localDate} AND sd.mode='{$mode}')";
        $currentCustomOpen = 'EXISTS (SELECT 1 FROM special_opening_days sd JOIN special_opening_intervals si ON si.special_opening_day_id=sd.id WHERE sd.place_id=p.id AND sd.local_date='.$localDate." AND sd.mode='custom' AND ((NOT si.closes_next_day AND ".$localTime.'>=si.opens_at AND '.$localTime.'<si.closes_at) OR (si.closes_next_day AND '.$localTime.'>=si.opens_at)))';
        $previousSpecialCarry = 'EXISTS (SELECT 1 FROM special_opening_days sd JOIN special_opening_intervals si ON si.special_opening_day_id=sd.id WHERE sd.place_id=p.id AND sd.local_date='.$localDate."-1 AND sd.mode='custom' AND si.closes_next_day AND ".$localTime.'<si.closes_at)';
        $weeklyOpen = 'EXISTS (SELECT 1 FROM weekly_opening_intervals wi WHERE wi.place_id=p.id AND ((wi.weekday='.$weekday.' AND ((NOT wi.closes_next_day AND '.$localTime.'>=wi.opens_at AND '.$localTime.'<wi.closes_at) OR (wi.closes_next_day AND '.$localTime.'>=wi.opens_at))) OR (wi.closes_next_day AND wi.weekday='.$previousWeekday.' AND '.$localTime.'<wi.closes_at)))';

        return '(CASE WHEN p.opening_hours_mode=\'unknown\' THEN NULL WHEN '.$currentMode('closed').' THEN FALSE WHEN '.$currentMode('open_24_hours').' THEN TRUE WHEN '.$currentMode('custom').' THEN '.$currentCustomOpen.' WHEN '.$previousSpecialCarry.' THEN TRUE WHEN p.opening_hours_mode=\'always_open\' THEN TRUE WHEN p.opening_hours_mode=\'scheduled\' AND NOT '.$currentSpecial.' THEN '.$weeklyOpen.' ELSE FALSE END)';
    }

    /** @param array<string, mixed> $row */
    private static function listItem(array $row): PlaceListItem
    {
        $mainPhoto = null;
        if (isset($row['main_photo_variants']) && \is_string($row['main_photo_variants'])) {
            $mainPhoto = json_decode($row['main_photo_variants'], true);
        }

        return new PlaceListItem((string) $row['id'], (string) $row['slug'], (string) $row['name'], (string) $row['short_description'], (string) $row['city'], self::namedItems($row['categories']), new AgeSummary((int) $row['min_age_months'], null === $row['max_age_months'] ? null : (int) $row['max_age_months']), (bool) $row['indoor'], (bool) $row['outdoor'], (bool) $row['free_entry'], (string) $row['verification_status'], self::namedItems($row['amenities']), null === $row['distance_meters'] ? null : (float) $row['distance_meters'], (float) $row['longitude'], (float) $row['latitude'], new OpeningStatus(null === $row['is_open_now'] ? null : (bool) $row['is_open_now']), (bool) $row['complete'], (float) $row['relevance_score'], $mainPhoto);
    }

    /** @param array<string, mixed> $row */
    private static function detailsItem(array $row): PlaceDetails
    {
        $mainPhoto = null;
        if (isset($row['main_photo_variants']) && \is_string($row['main_photo_variants'])) {
            $mainPhoto = json_decode($row['main_photo_variants'], true);
        }
        $photosList = [];
        if (isset($row['photos']) && \is_string($row['photos'])) {
            $rawPhotos = self::jsonList($row['photos']);
            foreach ($rawPhotos as $rawPhoto) {
                $pVariants = isset($rawPhoto['variants']) && (\is_array($rawPhoto['variants']) || \is_string($rawPhoto['variants']))
                    ? (\is_array($rawPhoto['variants']) ? $rawPhoto['variants'] : json_decode((string) $rawPhoto['variants'], true))
                    : [];
                $photosList[] = [
                    'id' => (string) $rawPhoto['id'],
                    'is_main' => (bool) $rawPhoto['is_main'],
                    'alt_text' => isset($rawPhoto['alt_text']) && \is_string($rawPhoto['alt_text']) ? $rawPhoto['alt_text'] : null,
                    'caption' => isset($rawPhoto['caption']) && \is_string($rawPhoto['caption']) ? $rawPhoto['caption'] : null,
                    'variants' => $pVariants,
                ];
            }
        }

        return new PlaceDetails((string) $row['id'], (string) $row['slug'], (string) $row['name'], (string) $row['short_description'], (string) $row['description'], (string) $row['city_name'], (string) $row['city_slug'], (string) $row['address_line1'], null === $row['address_line2'] ? null : (string) $row['address_line2'], (string) $row['postal_code'], (string) $row['country_code'], self::namedItems($row['categories']), self::namedItems($row['amenities']), self::ageZones($row['age_zones']), self::weeklyOpening($row['weekly_opening']), self::specialOpening($row['special_opening']), (bool) $row['indoor'], (bool) $row['outdoor'], (bool) $row['free_entry'], null === $row['price_description'] ? null : (string) $row['price_description'], null === $row['website_url'] ? null : (string) $row['website_url'], null === $row['phone'] ? null : (string) $row['phone'], (string) $row['verification_status'], (float) $row['longitude'], (float) $row['latitude'], $mainPhoto, $photosList);
    }

    /** @return list<array{slug: string, name: string}> */
    private static function namedItems(mixed $value): array
    {
        /** @var list<array{slug: string, name: string}> $items */
        $items = self::jsonList($value);

        return $items;
    }

    /** @return list<array{name: string, minAgeMonths: int, maxAgeMonths: ?int, notes: ?string}> */
    private static function ageZones(mixed $value): array
    {
        return array_map(static fn (array $item): array => ['name' => (string) $item['name'], 'minAgeMonths' => (int) $item['minAgeMonths'], 'maxAgeMonths' => null === $item['maxAgeMonths'] ? null : (int) $item['maxAgeMonths'], 'notes' => null === $item['notes'] ? null : (string) $item['notes']], self::jsonList($value));
    }

    /** @return list<array{weekday: int, sequence: int, opensAt: string, closesAt: string, closesNextDay: bool}> */
    private static function weeklyOpening(mixed $value): array
    {
        return array_map(static fn (array $item): array => ['weekday' => (int) $item['weekday'], 'sequence' => (int) $item['sequence'], 'opensAt' => (string) $item['opensAt'], 'closesAt' => (string) $item['closesAt'], 'closesNextDay' => (bool) $item['closesNextDay']], self::jsonList($value));
    }

    /** @return list<array{localDate: string, closed: bool, note: ?string}> */
    private static function specialOpening(mixed $value): array
    {
        return array_map(static fn (array $item): array => ['localDate' => (string) $item['localDate'], 'closed' => (bool) $item['closed'], 'note' => null === $item['note'] ? null : (string) $item['note']], self::jsonList($value));
    }

    /** @return list<array<string, mixed>> */
    private static function jsonList(mixed $value): array
    {
        if (\is_string($value)) {
            $value = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        }

        return \is_array($value) ? array_values($value) : [];
    }
}
