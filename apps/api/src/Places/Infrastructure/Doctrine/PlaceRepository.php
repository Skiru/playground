<?php

declare(strict_types=1);

namespace App\Places\Infrastructure\Doctrine;

use App\Places\Application\AdminPlaceSummary;
use App\Places\Application\ConcurrentPlaceModification;
use App\Places\Application\PlaceRepository as PlaceRepositoryPort;
use App\Places\Domain\Amenity;
use App\Places\Domain\Category;
use App\Places\Domain\City;
use App\Places\Domain\ExternalPlaceReference;
use App\Places\Domain\OpeningHoursMode;
use App\Places\Domain\Place;
use App\Places\Domain\PlaceAgeZone;
use App\Places\Domain\PlacePhoto;
use App\Places\Domain\PlaceStatus;
use App\Places\Domain\SpecialOpeningDay;
use App\Places\Domain\SpecialOpeningDayMode;
use App\Places\Domain\SpecialOpeningInterval;
use App\Places\Domain\ValueObject\AgeRange;
use App\Places\Domain\ValueObject\Coordinates;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use App\Places\Domain\VerificationStatus;
use App\Places\Domain\WeeklyOpeningInterval;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final readonly class PlaceRepository implements PlaceRepositoryPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function listForAdministration(
        ?string $search = null,
        ?string $status = null,
        ?string $city = null,
        ?string $sort = null,
        int $page = 1,
        int $pageSize = 20,
    ): array {
        $where = ['1=1'];
        $params = [];

        $countTypes = [];
        if (null !== $search && '' !== $search) {
            $where[] = '(p.name ILIKE :search OR p.slug ILIKE :search)';
            $params['search'] = '%'.$search.'%';
            $countTypes['search'] = \Doctrine\DBAL\ParameterType::STRING;
        }

        if (null !== $status && '' !== $status) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
            $countTypes['status'] = \Doctrine\DBAL\ParameterType::STRING;
        }

        if (null !== $city && '' !== $city) {
            $where[] = 'c.slug = :city';
            $params['city'] = $city;
            $countTypes['city'] = \Doctrine\DBAL\ParameterType::STRING;
        }

        $orderMap = [
            'name' => 'p.name ASC',
            'name_desc' => 'p.name DESC',
            'city' => 'c.name ASC',
            'city_desc' => 'c.name DESC',
            'status' => 'p.status ASC',
            'status_desc' => 'p.status DESC',
            'updated_at' => 'p.updated_at DESC',
            'updated_at_asc' => 'p.updated_at ASC',
        ];
        $orderBy = null !== $sort ? ($orderMap[$sort] ?? 'p.updated_at DESC, p.id') : 'p.updated_at DESC, p.id';

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM places p
            JOIN cities c ON c.id = p.city_id
            WHERE {$whereClause}
        ", $params, $countTypes);

        $offset = ($page - 1) * $pageSize;
        $params['limit'] = $pageSize;
        $params['offset'] = $offset;

        $types = $countTypes;
        $types['limit'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['offset'] = \Doctrine\DBAL\ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative("
            SELECT p.id, p.name, p.slug, p.status, p.verification_status, p.version, p.updated_at, c.name city,
                   (SELECT file_path FROM place_photos WHERE place_id = p.id AND is_main = true AND status = 'COMPLETED' LIMIT 1) as main_photo_path
            FROM places p
            JOIN cities c ON c.id = p.city_id
            WHERE {$whereClause}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ", $params, $types);

        $items = array_map(static fn (array $row): AdminPlaceSummary => new AdminPlaceSummary(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            PlaceStatus::from((string) $row['status']),
            (string) $row['city'],
            (int) $row['version'],
            VerificationStatus::from((string) $row['verification_status']),
            (string) $row['updated_at'],
            $row['main_photo_path'] ? (string) $row['main_photo_path'] : null
        ), $rows);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function get(string $id): Place
    {
        return $this->load($id, false);
    }

    public function getForUpdate(string $id): Place
    {
        return $this->load($id, true);
    }

    public function cityBySlug(string $slug): City
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cities WHERE slug=:slug AND enabled=true', ['slug' => $slug]);
        if (false === $row) {
            throw new \InvalidArgumentException('Enabled city does not exist.');
        }

        return $this->city($row);
    }

    public function allCities(): array
    {
        return array_map($this->city(...), $this->connection->fetchAllAssociative('SELECT * FROM cities ORDER BY name,id'));
    }

    public function categoryBySlug(string $slug): Category
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM categories WHERE slug=:slug AND enabled=true', ['slug' => $slug]);
        if (false === $row) {
            throw new \InvalidArgumentException('Enabled category does not exist.');
        }

        return $this->category($row);
    }

    public function allCategories(): array
    {
        return array_map($this->category(...), $this->connection->fetchAllAssociative('SELECT * FROM categories ORDER BY display_order,name,id'));
    }

    public function allAmenities(): array
    {
        return array_map($this->amenity(...), $this->connection->fetchAllAssociative('SELECT * FROM amenities ORDER BY display_order,name,id'));
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<Category>
     */
    public function categoriesBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM categories WHERE enabled=true AND slug IN (:slugs) ORDER BY display_order,id', ['slugs' => array_values(array_unique($slugs))], ['slugs' => ArrayParameterType::STRING]);
        if (\count($rows) !== \count(array_unique($slugs))) {
            throw new \InvalidArgumentException('Every category must exist and be enabled.');
        }

        return array_map($this->category(...), $rows);
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<Amenity>
     */
    public function amenitiesBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM amenities WHERE enabled=true AND slug IN (:slugs) ORDER BY display_order,id', ['slugs' => array_values(array_unique($slugs))], ['slugs' => ArrayParameterType::STRING]);
        if (\count($rows) !== \count(array_unique($slugs))) {
            throw new \InvalidArgumentException('Every amenity must exist and be enabled.');
        }

        return array_map($this->amenity(...), $rows);
    }

    public function add(Place $place): void
    {
        $this->connection->insert('places', $this->placeData($place) + ['id' => $place->id()->toRfc4122(), 'version' => $place->version()], $this->placeTypes());
        $this->replaceRelations($place);
    }

    public function save(Place $place, int $expectedVersion): void
    {
        if ($expectedVersion !== $place->version()) {
            throw new ConcurrentPlaceModification();
        }
        $nextVersion = $expectedVersion + 1;
        $updated = $this->connection->update('places', $this->placeData($place) + ['version' => $nextVersion], ['id' => $place->id()->toRfc4122(), 'version' => $expectedVersion], $this->placeTypes());
        if (1 !== $updated) {
            throw new ConcurrentPlaceModification();
        }
        $this->replaceRelations($place);
        $place->markPersisted($nextVersion);
    }

    private function load(string $id, bool $forUpdate): Place
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Invalid place identifier.');
        }
        $row = $this->connection->fetchAssociative('SELECT p.*, c.id city_record_id,c.name city_name,c.slug city_slug,c.country_code city_country_code,c.latitude city_latitude,c.longitude city_longitude,c.default_zoom,c.default_radius_km,c.timezone city_timezone,c.enabled city_enabled,c.created_at city_created_at,c.updated_at city_updated_at,cat.name primary_name,cat.slug primary_slug,cat.description primary_description,cat.icon_key primary_icon_key,cat.enabled primary_enabled,cat.display_order primary_display_order FROM places p JOIN cities c ON c.id=p.city_id JOIN categories cat ON cat.id=p.primary_category_id WHERE p.id=:id'.($forUpdate ? ' FOR UPDATE OF p' : ''), ['id' => $id]);
        if (false === $row) {
            throw new \InvalidArgumentException('Place does not exist.');
        }
        $city = City::reconstitute(Uuid::fromString((string) $row['city_record_id']), (string) $row['city_name'], (string) $row['city_slug'], (string) $row['city_country_code'], new Coordinates((float) $row['city_latitude'], (float) $row['city_longitude']), (int) $row['default_zoom'], (int) $row['default_radius_km'], (string) $row['city_timezone'], (bool) $row['city_enabled'], $this->date($row['city_created_at']), $this->date($row['city_updated_at']));
        $primary = Category::reconstitute(Uuid::fromString((string) $row['primary_category_id']), (string) $row['primary_name'], (string) $row['primary_slug'], null === $row['primary_description'] ? null : (string) $row['primary_description'], (string) $row['primary_icon_key'], (bool) $row['primary_enabled'], (int) $row['primary_display_order']);
        $place = Place::reconstitute(Uuid::fromString((string) $row['id']), (int) $row['version'], new PlaceName((string) $row['name']), new PlaceSlug((string) $row['slug']), (string) $row['short_description'], (string) $row['description'], (string) $row['address_line1'], (string) $row['postal_code'], $city, (string) $row['country_code'], new Coordinates((float) $row['latitude'], (float) $row['longitude']), (string) $row['timezone'], $primary, (bool) $row['indoor'], (bool) $row['outdoor'], (bool) $row['free_entry'], $this->date($row['created_at']), PlaceStatus::from((string) $row['status']), VerificationStatus::from((string) $row['verification_status']), $this->date($row['updated_at']), $this->nullableDate($row['published_at']), $this->nullableDate($row['verified_at']), null === $row['address_line2'] ? null : (string) $row['address_line2'], null === $row['price_description'] ? null : (string) $row['price_description'], null === $row['website_url'] ? null : (string) $row['website_url'], null === $row['phone'] ? null : (string) $row['phone'], OpeningHoursMode::from((string) $row['opening_hours_mode']));

        $categories = array_map($this->category(...), $this->connection->fetchAllAssociative('SELECT c.* FROM place_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.place_id=:id ORDER BY c.display_order,c.id', ['id' => $id]));
        $place->replaceCategories($categories, $primary, $place->updatedAt());
        $place->replaceAmenities(array_map($this->amenity(...), $this->connection->fetchAllAssociative('SELECT a.* FROM place_amenities pa JOIN amenities a ON a.id=pa.amenity_id WHERE pa.place_id=:id ORDER BY a.display_order,a.id', ['id' => $id])), $place->updatedAt());
        $zones = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM place_age_zones WHERE place_id=:id ORDER BY min_age_months,id', ['id' => $id]) as $zone) {
            $zones[] = new PlaceAgeZone($place, (string) $zone['name'], new AgeRange((int) $zone['min_age_months'], null === $zone['max_age_months'] ? null : (int) $zone['max_age_months']), null === $zone['notes'] ? null : (string) $zone['notes'], (string) $zone['source_type'], $this->nullableDate($zone['verified_at']));
        }
        $place->replaceAgeZones($zones, $place->updatedAt());
        $weekly = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM weekly_opening_intervals WHERE place_id=:id ORDER BY weekday,sequence', ['id' => $id]) as $interval) {
            $weekly[] = new WeeklyOpeningInterval($place, (int) $interval['weekday'], (int) $interval['sequence'], $this->time((string) $interval['opens_at']), $this->time((string) $interval['closes_at']), (bool) $interval['closes_next_day']);
        }
        $place->replaceWeeklyOpeningHours($weekly, $place->updatedAt());
        $days = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM special_opening_days WHERE place_id=:id ORDER BY local_date', ['id' => $id]) as $dayRow) {
            $day = new SpecialOpeningDay($place, $this->date($dayRow['local_date']), SpecialOpeningDayMode::from((string) $dayRow['mode']), null === $dayRow['note'] ? null : (string) $dayRow['note']);
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM special_opening_intervals WHERE special_opening_day_id=:id ORDER BY sequence', ['id' => $dayRow['id']]) as $interval) {
                $day->addInterval(new SpecialOpeningInterval($day, (int) $interval['sequence'], $this->time((string) $interval['opens_at']), $this->time((string) $interval['closes_at']), (bool) $interval['closes_next_day']));
            }
            $days[] = $day;
        }
        $place->replaceSpecialOpeningDays($days, $place->updatedAt());
        $references = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM external_place_references WHERE place_id=:id ORDER BY provider,external_id', ['id' => $id]) as $reference) {
            $references[] = new ExternalPlaceReference($place, (string) $reference['provider'], (string) $reference['external_id'], null === $reference['source_url'] ? null : (string) $reference['source_url'], $this->nullableDate($reference['imported_at']), $this->nullableDate($reference['last_verified_at']));
        }
        $place->replaceExternalReferences($references, $place->updatedAt());

        $photos = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM place_photos WHERE place_id=:id ORDER BY display_order, id', ['id' => $id]) as $photoRow) {
            $variants = null === $photoRow['variants'] ? null : json_decode((string) $photoRow['variants'], true);
            $photos[] = PlacePhoto::reconstitute(
                Uuid::fromString((string) $photoRow['id']),
                $place,
                (string) $photoRow['original_filename'],
                (string) $photoRow['file_path'],
                \App\Places\Domain\PlacePhotoStatus::from((string) $photoRow['status']),
                (bool) $photoRow['is_main'],
                (int) $photoRow['display_order'],
                $photoRow['alt_text'] ? (string) $photoRow['alt_text'] : null,
                $photoRow['caption'] ? (string) $photoRow['caption'] : null,
                $variants,
                (int) $photoRow['processing_generation'],
                $photoRow['failure_code'] ? (string) $photoRow['failure_code'] : null,
                $photoRow['processed_at'] ? $this->date($photoRow['processed_at']) : null,
                $this->date($photoRow['created_at']),
                $this->date($photoRow['updated_at'])
            );
        }
        $place->replacePhotos($photos, $place->updatedAt());

        return $place;
    }

    /** @return array<string, mixed> */
    private function placeData(Place $place): array
    {
        $coordinates = $place->coordinates();

        return ['city_id' => $place->city()->id()->toRfc4122(), 'primary_category_id' => $place->primaryCategory()->id()->toRfc4122(), 'slug' => $place->slug(), 'name' => $place->name(), 'normalized_name' => mb_strtolower($place->name()), 'short_description' => $place->shortDescription(), 'description' => $place->description(), 'status' => $place->status()->value, 'verification_status' => $place->verificationStatus()->value, 'opening_hours_mode' => $place->openingHoursMode()->value, 'address_line1' => $place->addressLine1(), 'address_line2' => $place->addressLine2(), 'postal_code' => $place->postalCode(), 'country_code' => $place->countryCode(), 'location' => \sprintf('SRID=4326;POINT(%F %F)', $coordinates->longitude, $coordinates->latitude), 'latitude' => $coordinates->latitude, 'longitude' => $coordinates->longitude, 'timezone' => $place->timezone(), 'indoor' => $place->indoor(), 'outdoor' => $place->outdoor(), 'free_entry' => $place->freeEntry(), 'price_description' => $place->priceDescription(), 'website_url' => $place->websiteUrl(), 'phone' => $place->phone(), 'verified_at' => $place->verifiedAt(), 'published_at' => $place->publishedAt(), 'created_at' => $place->createdAt(), 'updated_at' => $place->updatedAt()];
    }

    /** @return array<string, mixed> */
    private function placeTypes(): array
    {
        return ['location' => 'geography', 'indoor' => Types::BOOLEAN, 'outdoor' => Types::BOOLEAN, 'free_entry' => Types::BOOLEAN, 'verified_at' => Types::DATETIME_IMMUTABLE, 'published_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE];
    }

    private function replaceRelations(Place $place): void
    {
        $id = $place->id()->toRfc4122();
        $this->connection->executeStatement('DELETE FROM place_categories WHERE place_id=:id', ['id' => $id]);
        foreach ($place->categories() as $category) {
            $this->connection->insert('place_categories', ['place_id' => $id, 'category_id' => $category->id()->toRfc4122()]);
        }
        $this->connection->executeStatement('DELETE FROM place_amenities WHERE place_id=:id', ['id' => $id]);
        foreach ($place->amenities() as $amenity) {
            $this->connection->insert('place_amenities', ['place_id' => $id, 'amenity_id' => $amenity->id()->toRfc4122()]);
        }
        foreach (['place_age_zones', 'weekly_opening_intervals', 'special_opening_days', 'external_place_references', 'place_photos'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table.' WHERE place_id=:id', ['id' => $id]);
        }
        foreach ($place->ageZones() as $zone) {
            $range = $zone->ageRange();
            $this->connection->insert('place_age_zones', ['id' => $zone->id()->toRfc4122(), 'place_id' => $id, 'name' => $zone->name(), 'min_age_months' => $range->minAgeMonths, 'max_age_months' => $range->maxAgeMonths, 'notes' => $zone->notes(), 'source_type' => $zone->sourceType(), 'verified_at' => $zone->verifiedAt()], ['verified_at' => Types::DATETIME_IMMUTABLE]);
        }
        foreach ($place->weeklyOpeningHours() as $interval) {
            $this->connection->insert('weekly_opening_intervals', ['id' => $interval->id()->toRfc4122(), 'place_id' => $id, 'weekday' => $interval->weekday(), 'sequence' => $interval->sequence(), 'opens_at' => $interval->opensAt()->format('H:i:s'), 'closes_at' => $interval->closesAt()->format('H:i:s'), 'closes_next_day' => (int) $interval->closesNextDay()]);
        }
        foreach ($place->specialOpeningDays() as $day) {
            $dayId = $day->id()->toRfc4122();
            $this->connection->insert('special_opening_days', ['id' => $dayId, 'place_id' => $id, 'local_date' => $day->localDate()->format('Y-m-d'), 'mode' => $day->mode()->value, 'note' => $day->note()]);
            foreach ($day->intervals() as $interval) {
                $this->connection->insert('special_opening_intervals', ['id' => $interval->id()->toRfc4122(), 'special_opening_day_id' => $dayId, 'sequence' => $interval->sequence(), 'opens_at' => $interval->opensAt()->format('H:i:s'), 'closes_at' => $interval->closesAt()->format('H:i:s'), 'closes_next_day' => (int) $interval->closesNextDay()]);
            }
        }
        foreach ($place->externalReferences() as $reference) {
            $this->connection->insert('external_place_references', ['id' => $reference->id()->toRfc4122(), 'place_id' => $id, 'provider' => $reference->provider(), 'external_id' => $reference->externalId(), 'source_url' => $reference->sourceUrl(), 'imported_at' => $reference->importedAt(), 'last_verified_at' => $reference->lastVerifiedAt()], ['imported_at' => Types::DATETIME_IMMUTABLE, 'last_verified_at' => Types::DATETIME_IMMUTABLE]);
        }
        foreach ($place->photos() as $photo) {
            $this->connection->insert('place_photos', [
                'id' => $photo->id()->toRfc4122(),
                'place_id' => $id,
                'original_filename' => $photo->originalFilename(),
                'file_path' => $photo->filePath(),
                'status' => $photo->status()->value,
                'is_main' => (int) $photo->isMain(),
                'display_order' => $photo->displayOrder(),
                'alt_text' => $photo->altText(),
                'caption' => $photo->caption(),
                'variants' => $photo->variants() ? json_encode($photo->variants(), \JSON_THROW_ON_ERROR) : null,
                'processing_generation' => $photo->processingGeneration(),
                'failure_code' => $photo->failureCode(),
                'processed_at' => $photo->processedAt(),
                'created_at' => $photo->createdAt(),
                'updated_at' => $photo->updatedAt(),
            ], [
                'processed_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function city(array $row): City
    {
        return City::reconstitute(Uuid::fromString((string) $row['id']), (string) $row['name'], (string) $row['slug'], (string) $row['country_code'], new Coordinates((float) $row['latitude'], (float) $row['longitude']), (int) $row['default_zoom'], (int) $row['default_radius_km'], (string) $row['timezone'], (bool) $row['enabled'], $this->date($row['created_at']), $this->date($row['updated_at']));
    }

    /** @param array<string, mixed> $row */
    private function category(array $row): Category
    {
        return Category::reconstitute(Uuid::fromString((string) $row['id']), (string) $row['name'], (string) $row['slug'], null === $row['description'] ? null : (string) $row['description'], (string) $row['icon_key'], (bool) $row['enabled'], (int) $row['display_order']);
    }

    /** @param array<string, mixed> $row */
    private function amenity(array $row): Amenity
    {
        return Amenity::reconstitute(Uuid::fromString((string) $row['id']), (string) $row['name'], (string) $row['slug'], (string) $row['amenity_group'], (string) $row['icon_key'], (bool) $row['enabled'], (int) $row['display_order']);
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        return $value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string) $value);
    }

    private function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : $this->date($value);
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable('1970-01-01 '.$value);
    }
}
