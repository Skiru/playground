<?php

declare(strict_types=1);

namespace App\Places\Infrastructure\Doctrine;

use App\Places\Application\AdminPlaceGateway;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalAdminPlaceGateway implements AdminPlaceGateway
{
    public function __construct(private Connection $connection)
    {
    }

    public function list(): array
    {
        return $this->connection->fetchAllAssociative('SELECT p.id,p.name,p.slug,p.status,c.name city FROM places p JOIN cities c ON c.id=p.city_id ORDER BY p.updated_at DESC,p.id');
    }

    public function createDraft(array $data): string
    {
        $id = Uuid::v7()->toRfc4122();
        $cityId = (string) $this->connection->fetchOne('SELECT id FROM cities WHERE slug=:slug AND enabled=true', ['slug' => (string) ($data['city'] ?? '')]);
        $categoryId = (string) $this->connection->fetchOne('SELECT id FROM categories WHERE slug=:slug AND enabled=true', ['slug' => (string) ($data['category'] ?? '')]);
        if ('' === $cityId || '' === $categoryId) {
            throw new \InvalidArgumentException('Enabled city and category are required.');
        }
        $latitude = (float) ($data['latitude'] ?? 0);
        $longitude = (float) ($data['longitude'] ?? 0);
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Invalid coordinate axes.');
        }
        $now = new \DateTimeImmutable();
        $this->connection->transactional(function () use ($data, $id, $cityId, $categoryId, $latitude, $longitude, $now): void {
            $this->connection->executeStatement('INSERT INTO places (id,city_id,primary_category_id,slug,name,normalized_name,short_description,description,status,verification_status,address_line1,postal_code,country_code,location,latitude,longitude,timezone,indoor,outdoor,free_entry,created_at,updated_at) VALUES (:id,:city,:category,:slug,:name,:normalized,:short,:description,\'draft\',\'unverified\',:address,:postal,\'PL\',ST_SetSRID(ST_MakePoint(:longitude,:latitude),4326)::geography,:latitude,:longitude,\'Europe/Warsaw\',:indoor,:outdoor,:free,:now,:now)', ['id' => $id, 'city' => $cityId, 'category' => $categoryId, 'slug' => (string) ($data['slug'] ?? ''), 'name' => (string) ($data['name'] ?? ''), 'normalized' => mb_strtolower((string) ($data['name'] ?? '')), 'short' => (string) ($data['shortDescription'] ?? ''), 'description' => (string) ($data['description'] ?? ''), 'address' => (string) ($data['addressLine1'] ?? ''), 'postal' => (string) ($data['postalCode'] ?? ''), 'longitude' => $longitude, 'latitude' => $latitude, 'indoor' => !empty($data['indoor']) ? 'true' : 'false', 'outdoor' => !empty($data['outdoor']) ? 'true' : 'false', 'free' => !empty($data['freeEntry']) ? 'true' : 'false', 'now' => $now->format('Y-m-d H:i:s')]);
            $this->connection->insert('place_categories', ['place_id' => $id, 'category_id' => $categoryId]);
            if (isset($data['minAgeMonths']) && '' !== (string) $data['minAgeMonths']) {
                $this->connection->insert('place_age_zones', ['id' => Uuid::v7()->toRfc4122(), 'place_id' => $id, 'name' => 'Strefa główna', 'min_age_months' => (int) $data['minAgeMonths'], 'max_age_months' => '' === (string) ($data['maxAgeMonths'] ?? '') ? null : (int) $data['maxAgeMonths'], 'source_type' => 'admin']);
            }
        });

        return $id;
    }

    public function publicationProblems(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT p.*, EXISTS(SELECT 1 FROM place_categories pc WHERE pc.place_id=p.id) has_category, EXISTS(SELECT 1 FROM place_age_zones z WHERE z.place_id=p.id) has_age FROM places p WHERE p.id=:id', ['id' => $id]);
        if (false === $row) {
            throw new \InvalidArgumentException('Place does not exist.');
        }
        $problems = [];
        if (!\in_array($row['status'], ['pending_review', 'needs_reverification'], true)) {
            $problems[] = 'workflowStatus';
        }
        foreach (['name', 'slug', 'short_description', 'description', 'address_line1', 'postal_code', 'timezone'] as $field) {
            if ('' === trim((string) $row[$field])) {
                $problems[] = $field;
            }
        }
        if (!(bool) $row['has_category']) {
            $problems[] = 'categories';
        }
        if (!(bool) $row['has_age']) {
            $problems[] = 'ageZones';
        }
        if (!(bool) $row['indoor'] && !(bool) $row['outdoor']) {
            $problems[] = 'indoorOrOutdoor';
        }

        return $problems;
    }

    public function changeStatus(string $id, string $status, ?\DateTimeImmutable $publishedAt): void
    {
        if (!\in_array($status, ['published', 'draft', 'pending_review', 'archived', 'needs_reverification', 'temporarily_closed'], true)) {
            throw new \InvalidArgumentException('Unsupported place status.');
        }
        $updated = $this->connection->executeStatement('UPDATE places SET status=:status,published_at=:published,verified_at=CASE WHEN :status=\'published\' THEN :published ELSE verified_at END,verification_status=CASE WHEN :status=\'published\' THEN \'admin_verified\' ELSE verification_status END,updated_at=NOW() WHERE id=:id', ['status' => $status, 'published' => $publishedAt?->format('Y-m-d H:i:s'), 'id' => $id]);
        if (1 !== $updated) {
            throw new \InvalidArgumentException('Place does not exist.');
        }
    }
}
