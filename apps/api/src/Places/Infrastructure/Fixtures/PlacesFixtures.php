<?php

declare(strict_types=1);

namespace App\Places\Infrastructure\Fixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

final class PlacesFixtures extends Fixture
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->connection->executeStatement('TRUNCATE content_reports, moderation_actions, forum_posts, forum_threads, forum_categories, external_place_references, special_opening_intervals, special_opening_days, weekly_opening_intervals, place_age_zones, place_amenities, place_categories, reviews, place_comments, places, amenities, categories, cities, users CASCADE');
        $now = '2026-07-16 08:00:00';
        $this->connection->insert('users', ['id' => self::id(1), 'email' => 'admin@example.test', 'display_name' => 'E2E Administrator', 'password_hash' => '$2y$04$1gdB2/YIo.5sVRE7JpMdR.AL2c9cef8DPnEm/4fDHp/syvn/zOTBK', 'google_subject' => null, 'roles' => json_encode(['ROLE_ADMIN'], \JSON_THROW_ON_ERROR), 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null]);
        $cities = [
            ['Warszawa', 'warszawa', 52.2297, 21.0122],
            ['Kraków', 'krakow', 50.0647, 19.9450],
            ['Wrocław', 'wroclaw', 51.1079, 17.0385],
            ['Gdańsk', 'gdansk', 54.3520, 18.6466],
            ['Poznań', 'poznan', 52.4064, 16.9252],
        ];
        foreach ($cities as $index => [$name, $slug, $lat, $lon]) {
            $this->connection->executeStatement(
                'INSERT INTO cities (id,name,slug,country_code,center,latitude,longitude,default_zoom,default_radius_km,timezone,enabled,created_at,updated_at) VALUES (:id,:name,:slug,\'PL\',ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography,:lat,:lon,12,15,\'Europe/Warsaw\',true,:now,:now)',
                ['id' => self::id(100 + $index), 'name' => $name, 'slug' => $slug, 'lat' => $lat, 'lon' => $lon, 'now' => $now],
            );
        }

        $categories = ['bawialnie' => 'Bawialnie', 'parki' => 'Parki rodzinne', 'kawiarnie' => 'Kawiarnie rodzinne', 'muzea' => 'Muzea', 'sport' => 'Sport', 'natura' => 'Natura'];
        $categoryIds = [];
        $order = 1;
        foreach ($categories as $slug => $name) {
            $categoryIds[$slug] = self::id(200 + $order);
            $this->connection->insert('categories', ['id' => $categoryIds[$slug], 'name' => $name, 'slug' => $slug, 'description' => 'Demonstracyjna kategoria miejsc rodzinnych.', 'icon_key' => $slug, 'enabled' => true, 'display_order' => $order++]);
        }

        $amenities = ['przewijak', 'toaleta-rodzinna', 'wózkownia', 'parking', 'rowery', 'wifi', 'krzesełka', 'menu-dziecięce', 'bez-barier', 'cisza', 'ogród', 'plac-zabaw', 'klimatyzacja', 'szatnia', 'woda'];
        $amenityIds = [];
        foreach ($amenities as $index => $slug) {
            $amenityIds[$slug] = self::id(300 + $index);
            $this->connection->insert('amenities', ['id' => $amenityIds[$slug], 'name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'amenity_group' => 'family', 'icon_key' => $slug, 'enabled' => true, 'display_order' => $index + 1]);
        }

        $names = ['Demo Bawialnia Mokotów', 'Demo Park Rodzinny Centrum', 'Demo Kawiarnia z Kącikiem', 'Demo Ogród Odkrywców', 'Demo Muzeum Małych Pytań', 'Demo Sala Ruchu', 'Demo Leśna Przystań', 'Demo Pracownia Rodzinna', 'Demo Plac Zabaw Nad Rzeką', 'Demo Klub Malucha', 'Demo Centrum Eksperymentów', 'Demo Zielony Dziedziniec', 'Demo Rodzinny Punkt Widokowy', 'Demo Dom Zabaw', 'Demo Ścieżka Przyrodnicza'];
        $categorySlugs = array_keys($categories);
        foreach ($names as $index => $name) {
            $placeId = self::id(400 + $index);
            $cityIndex = $index % \count($cities);
            $categorySlug = $categorySlugs[$index % \count($categorySlugs)];
            $status = match ($index) {
                12 => 'draft', 13 => 'pending_review', 14 => 'temporarily_closed', default => 'published',
            };
            $publishedAt = 'published' === $status ? $now : null;
            $lat = $cities[$cityIndex][2] + (($index % 3) * 0.012);
            $lon = $cities[$cityIndex][3] + (($index % 4) * 0.014);
            $slug = 'demo-'.($index + 1).'-'.str_replace(' ', '-', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
            $this->connection->executeStatement(
                'INSERT INTO places (id,city_id,primary_category_id,slug,name,normalized_name,short_description,description,status,verification_status,opening_hours_mode,address_line1,postal_code,country_code,location,latitude,longitude,timezone,indoor,outdoor,free_entry,verified_at,published_at,created_at,updated_at,version) VALUES (:id,:city,:category,:slug,:name,:normalized,:short,:description,:status,:verification,\'scheduled\',:address,\'00-001\',\'PL\',ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography,:lat,:lon,\'Europe/Warsaw\',:indoor,:outdoor,:free,:verified,:published,:now,:now,1)',
                ['id' => $placeId, 'city' => self::id(100 + $cityIndex), 'category' => $categoryIds[$categorySlug], 'slug' => $slug, 'name' => $name, 'normalized' => mb_strtolower($name), 'short' => 'Demonstracyjne miejsce dla rodzin o jawnie opisanym zakresie wieku.', 'description' => 'Dane demonstracyjne pokazujące katalog FamilyPlaces. Przed wizytą należy potwierdzić aktualne informacje.', 'status' => $status, 'verification' => 'published' === $status ? 'admin_verified' : 'unverified', 'address' => 'Demo ulica '.($index + 1), 'lon' => $lon, 'lat' => $lat, 'indoor' => 0 === $index % 2 ? 'true' : 'false', 'outdoor' => 0 !== $index % 2 ? 'true' : 'false', 'free' => 0 === $index % 3 ? 'true' : 'false', 'verified' => $publishedAt, 'published' => $publishedAt, 'now' => $now],
            );
            $this->connection->insert('place_categories', ['place_id' => $placeId, 'category_id' => $categoryIds[$categorySlug]]);
            foreach (\array_slice($amenities, 0, 2 + ($index % 4)) as $amenity) {
                $this->connection->insert('place_amenities', ['place_id' => $placeId, 'amenity_id' => $amenityIds[$amenity]]);
            }
            $this->connection->insert('place_age_zones', ['id' => self::id(500 + $index), 'place_id' => $placeId, 'name' => 'Strefa rodzinna', 'min_age_months' => ($index % 4) * 12, 'max_age_months' => 72 + (($index % 5) * 24), 'notes' => null, 'source_type' => 'admin', 'verified_at' => $publishedAt]);
            $this->connection->insert('weekly_opening_intervals', ['id' => self::id(600 + $index), 'place_id' => $placeId, 'weekday' => 1 + ($index % 7), 'sequence' => 1, 'opens_at' => '09:00:00', 'closes_at' => 0 === $index % 5 ? '01:00:00' : '18:00:00', 'closes_next_day' => 0 === $index % 5 ? 'true' : 'false']);
        }
        $this->connection->insert('special_opening_days', ['id' => self::id(700), 'place_id' => self::id(400), 'local_date' => '2026-12-24', 'mode' => 'closed', 'note' => 'Wyjątek demonstracyjny']);

        $forumCategories = [
            ['Warszawa', 'warszawa', 'Dyskusje dla rodziców w Warszawie.'],
            ['Kraków', 'krakow', 'Dyskusje dla rodziców w Krakowie.'],
            ['Ogólne', 'ogolne', 'Ogólne pytania, porady i rekomendacje dla rodzin.'],
        ];
        foreach ($forumCategories as $fOrder => [$fName, $fSlug, $fDesc]) {
            $this->connection->insert('forum_categories', [
                'id' => self::id(800 + $fOrder),
                'slug' => $fSlug,
                'name' => $fName,
                'description' => $fDesc,
                'display_order' => $fOrder + 1,
                'active' => true,
            ]);
        }
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-7000-8000-%012d', $number);
    }
}
