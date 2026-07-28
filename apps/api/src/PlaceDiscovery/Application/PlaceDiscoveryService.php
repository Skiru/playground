<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\Port\DuplicatePlaceLookup;
use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;
use App\PlaceDiscovery\Domain\DiscoveryClassification;
use App\PlaceDiscovery\Domain\NormalizedPlace;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\PlaceCommandHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PlaceDiscoveryService
{
    public function __construct(private Connection $connection, private PlaceCommandHandler $places, private DuplicatePlaceLookup $duplicates, private PlaceNormalizer $normalizer)
    {
    }

    /** @return 'inserted'|'updated'|'linked' */
    public function import(string $runId, ProviderPlace $source, NormalizedPlace $normalized, DiscoveryClassification $classification): string
    {
        $snapshot = json_encode($source->snapshot, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (\strlen($snapshot) > 32_768) {
            throw new \DomainException('Bounded source snapshot exceeds 32 KiB.');
        }
        $hash = hash('sha256', $snapshot);
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $this->connection->transactional(function () use ($runId, $source, $normalized, $classification, $snapshot, $hash, $now): string {
            $link = $this->connection->fetchAssociative('SELECT id FROM place_source_links WHERE source = ? AND external_id = ? FOR UPDATE', ['overture', $source->externalId]);
            if (false !== $link) {
                $this->connection->executeStatement('UPDATE place_source_links SET source_release = ?, last_seen_at = ?, last_payload_hash = ? WHERE id = ?', [$source->release, $now, $hash, $link['id']]);

                return 'linked';
            }
            $existing = $this->connection->fetchAssociative('SELECT id, status, manually_edited_at FROM place_candidates WHERE source = ? AND external_id = ? FOR UPDATE', ['overture', $source->externalId]);
            $values = $this->values($runId, $source, $normalized, $classification, $snapshot, $hash, $now);
            if (false === $existing) {
                $this->connection->insert('place_candidates', ['id' => Uuid::v7()->toRfc4122(), 'first_seen_at' => $now, 'created_at' => $now, ...$values]);

                return 'inserted';
            }
            $protected = \in_array($existing['status'], [CandidateStatus::APPROVED->value, CandidateStatus::REJECTED->value, CandidateStatus::DUPLICATE->value], true);
            if (!$protected && null === $existing['manually_edited_at']) {
                $this->connection->update('place_candidates', $values, ['id' => $existing['id']]);
            } else {
                $this->connection->update('place_candidates', ['discovery_run_id' => $runId, 'source_release' => $source->release, 'source_record_version' => $source->recordVersion, 'source_payload_hash' => $hash, 'source_snapshot' => $snapshot, 'last_seen_at' => $now, 'updated_at' => $now, 'source_changed_after_edit' => null !== $existing['manually_edited_at'] ? 'true' : 'false', 'source_closed_review_required' => CandidateStatus::APPROVED->value === $existing['status'] && 'closed_permanently' === $source->operatingStatus ? 'true' : 'false'], ['id' => $existing['id']]);
            }

            return 'updated';
        });
    }

    public function approve(string $candidateId, int $expectedVersion, string $reviewer): string
    {
        return $this->connection->transactional(function () use ($candidateId, $expectedVersion, $reviewer): string {
            $candidate = $this->connection->fetchAssociative(<<<'SQL'
SELECT c.*, category.slug AS category_slug, city.slug AS city_slug
FROM place_candidates c
LEFT JOIN categories category ON category.id = c.suggested_place_category_id
LEFT JOIN cities city ON lower(city.name) = lower(c.locality)
WHERE c.id = ? FOR UPDATE OF c
SQL, [$candidateId]);
            if (false === $candidate) {
                throw new \DomainException('Candidate was not found.');
            }
            if ((int) $candidate['version'] !== $expectedVersion) {
                throw new ConcurrentCandidateModification();
            }
            if (CandidateStatus::APPROVED->value === $candidate['status'] && null !== $candidate['approved_place_id']) {
                return (string) $candidate['approved_place_id'];
            }
            if (!\in_array($candidate['status'], [CandidateStatus::PENDING->value, CandidateStatus::POSSIBLE_DUPLICATE->value], true)) {
                throw new \DomainException('Candidate is not approvable.');
            }
            foreach (['name', 'address_line1', 'postal_code', 'country_code', 'category_slug', 'city_slug'] as $required) {
                if (null === $candidate[$required] || '' === trim((string) $candidate[$required])) {
                    throw new \DomainException('Candidate is missing mandatory Place field: '.$required);
                }
            }
            if (false !== $this->connection->fetchOne('SELECT 1 FROM place_source_links WHERE source = ? AND external_id = ?', [$candidate['source'], $candidate['external_id']])) {
                throw new \DomainException('This source identity is already linked to a Place.');
            }
            $slugBase = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower((string) $candidate['name'])) ?: 'place') ?? 'place';
            $slug = mb_substr(trim($slugBase, '-'), 0, 70).'-'.substr(str_replace('-', '', $candidateId), 0, 8);
            $placeId = $this->places->create(new CreatePlaceDraft((string) $candidate['name'], $slug, 'Kandydat zatwierdzony przez administratora.', 'Miejsce utworzone jako szkic z danych Overture Maps. Wymaga uzupełnienia i publikacji w osobnym kroku.', (string) $candidate['address_line1'], (string) $candidate['postal_code'], (string) $candidate['city_slug'], (string) $candidate['country_code'], (float) $candidate['latitude'], (float) $candidate['longitude'], 'Europe/Warsaw', (string) $candidate['category_slug'], true, true, false, websiteUrl: $candidate['website'], phone: $candidate['phone'], externalReferences: [new ExternalReferenceInput('overture', (string) $candidate['external_id'], null)]));
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $this->connection->insert('place_source_links', ['id' => Uuid::v7()->toRfc4122(), 'place_id' => $placeId, 'source' => $candidate['source'], 'external_id' => $candidate['external_id'], 'source_release' => $candidate['source_release'], 'first_linked_at' => $now, 'last_seen_at' => $now, 'last_payload_hash' => $candidate['source_payload_hash']]);
            $changed = $this->connection->executeStatement('UPDATE place_candidates SET status = ?, approved_place_id = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?', [CandidateStatus::APPROVED->value, $placeId, $reviewer, $now, $now, $candidateId, $expectedVersion]);
            if (1 !== $changed) {
                throw new ConcurrentCandidateModification();
            }

            return $placeId;
        });
    }

    public function reject(string $id, int $version, string $reviewer, string $reason): void
    {
        if ('' === trim($reason)) {
            throw new \DomainException('A rejection reason is required.');
        }
        $this->transition($id, $version, $reviewer, CandidateStatus::REJECTED, ['PENDING', 'NEEDS_MAPPING', 'POSSIBLE_DUPLICATE'], ['rejection_reason' => trim($reason)]);
    }

    public function markDuplicate(string $id, int $version, string $reviewer, string $placeId): void
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM places WHERE id = ?', [$placeId])) {
            throw new \DomainException('Duplicate Place does not exist.');
        }
        $this->transition($id, $version, $reviewer, CandidateStatus::DUPLICATE, ['PENDING', 'POSSIBLE_DUPLICATE'], ['possible_duplicate_place_id' => $placeId]);
    }

    /** @param array<string, mixed> $draft */
    public function editCandidate(string $id, int $version, array $draft): void
    {
        $name = trim((string) ($draft['name'] ?? ''));
        $latitude = filter_var($draft['latitude'] ?? null, \FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($draft['longitude'] ?? null, \FILTER_VALIDATE_FLOAT);
        $country = strtoupper(trim((string) ($draft['country_code'] ?? '')));
        $categoryId = trim((string) ($draft['category_id'] ?? ''));
        if ('' === $name || false === $latitude || false === $longitude || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \DomainException('Candidate draft has invalid name, coordinates, or country.');
        }
        if ('' !== $categoryId && false === $this->connection->fetchOne('SELECT 1 FROM categories WHERE id = ? AND enabled = true', [$categoryId])) {
            throw new \DomainException('Selected FamilyPlaces category does not exist.');
        }
        $website = trim((string) ($draft['website'] ?? '')) ?: null;
        $host = null;
        if (null !== $website) {
            $host = parse_url(str_contains($website, '://') ? $website : 'https://'.$website, \PHP_URL_HOST);
            $host = \is_string($host) ? preg_replace('/^www\./i', '', mb_strtolower($host)) : null;
        }
        $phone = trim((string) ($draft['phone'] ?? '')) ?: null;
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $changed = $this->connection->executeStatement("UPDATE place_candidates SET name = ?, normalized_name = ?, address_line1 = ?, postal_code = ?, locality = ?, country_code = ?, latitude = ?, longitude = ?, website = ?, normalized_website_host = ?, phone = ?, normalized_phone = ?, suggested_place_category_id = NULLIF(?, '')::uuid, status = CASE WHEN NULLIF(?, '') IS NULL THEN 'NEEDS_MAPPING' WHEN status = 'NEEDS_MAPPING' THEN 'PENDING' ELSE status END, manually_edited_at = ?, source_changed_after_edit = false, updated_at = ?, version = version + 1 WHERE id = ? AND version = ? AND status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE')", [$name, $this->normalizer->comparison($name), trim((string) ($draft['address_line1'] ?? '')) ?: null, trim((string) ($draft['postal_code'] ?? '')) ?: null, trim((string) ($draft['locality'] ?? '')) ?: null, $country, $latitude, $longitude, $website, $host, $phone, null === $phone ? null : preg_replace('/(?!^\+)\D+/', '', $phone), $categoryId, $categoryId, $now, $now, $id, $version]);
        if (1 !== $changed) {
            throw new ConcurrentCandidateModification();
        }
    }

    public function refreshCandidateFromSource(string $id, int $version): void
    {
        $candidate = $this->connection->fetchAssociative("SELECT source_snapshot FROM place_candidates WHERE id = ? AND version = ? AND status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE')", [$id, $version]);
        if (false === $candidate) {
            throw new ConcurrentCandidateModification();
        }
        $snapshot = json_decode((string) $candidate['source_snapshot'], true, 32, \JSON_THROW_ON_ERROR);
        $name = trim((string) ($snapshot['name'] ?? ''));
        if ('' === $name) {
            throw new \DomainException('Source snapshot has no usable name.');
        }
        $address = \is_array($snapshot['address'] ?? null) ? $snapshot['address'] : [];
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        if (1 !== $this->connection->executeStatement('UPDATE place_candidates SET name = ?, normalized_name = ?, address_line1 = ?, postal_code = ?, locality = ?, country_code = ?, website = ?, phone = ?, manually_edited_at = NULL, source_changed_after_edit = false, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?', [$name, $this->normalizer->comparison($name), $address['line1'] ?? null, $address['postcode'] ?? null, $address['locality'] ?? null, isset($address['country']) ? strtoupper((string) $address['country']) : null, $snapshot['website'] ?? null, $snapshot['phone'] ?? null, $now, $id, $version])) {
            throw new ConcurrentCandidateModification();
        }
    }

    /**
     * @param list<string>               $allowed
     * @param array<string, scalar|null> $extra
     */
    private function transition(string $id, int $version, string $reviewer, CandidateStatus $next, array $allowed, array $extra): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $set = 'status = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?, version = version + 1';
        $params = [$next->value, $reviewer, $now, $now];
        foreach ($extra as $column => $value) {
            $set .= ', '.$column.' = ?';
            $params[] = $value;
        }
        $params = [...$params, $id, $version, ...$allowed];
        $allowedPlaceholders = implode(',', array_fill(0, \count($allowed), '?'));
        if (1 !== $this->connection->executeStatement('UPDATE place_candidates SET '.$set.' WHERE id = ? AND version = ? AND status IN ('.$allowedPlaceholders.')', $params)) {
            throw new ConcurrentCandidateModification();
        }
    }

    /** @return array<string, mixed> */
    private function values(string $runId, ProviderPlace $source, NormalizedPlace $normalized, DiscoveryClassification $classification, string $snapshot, string $hash, string $now): array
    {
        $categoryId = null === $classification->category ? null : $this->connection->fetchOne('SELECT id FROM categories WHERE slug = ? AND enabled = true', [$classification->category]);
        $duplicate = $this->duplicates->assess($source, $normalized);
        $status = $duplicate->score >= 70 ? CandidateStatus::POSSIBLE_DUPLICATE->value : (false === $categoryId ? CandidateStatus::NEEDS_MAPPING->value : $classification->status->value);

        return ['discovery_run_id' => $runId, 'source' => 'overture', 'external_id' => $source->externalId, 'source_release' => $source->release, 'source_record_version' => $source->recordVersion, 'source_payload_hash' => $hash, 'source_snapshot' => $snapshot, 'name' => $normalized->name, 'normalized_name' => $normalized->normalizedName, 'address_line1' => $source->addressLine1, 'postal_code' => $source->postalCode, 'locality' => $source->locality, 'country_code' => $source->countryCode, 'latitude' => $source->latitude, 'longitude' => $source->longitude, 'website' => $source->website, 'normalized_website_host' => $normalized->websiteHost, 'phone' => $source->phone, 'normalized_phone' => $normalized->phone, 'source_categories' => json_encode($source->categories, \JSON_THROW_ON_ERROR), 'suggested_place_category_id' => false === $categoryId ? null : $categoryId, 'confidence' => $source->confidence, 'operating_status' => $source->operatingStatus, 'discovery_score' => $classification->score, 'discovery_reasons' => json_encode($classification->reasons, \JSON_THROW_ON_ERROR), 'duplicate_score' => 0 === $duplicate->score ? null : $duplicate->score, 'duplicate_reasons' => [] === $duplicate->reasons ? null : json_encode($duplicate->reasons, \JSON_THROW_ON_ERROR), 'possible_duplicate_place_id' => $duplicate->placeIds[0] ?? null, 'status' => $status, 'last_seen_at' => $now, 'updated_at' => $now];
    }
}

final class ConcurrentCandidateModification extends \DomainException
{
}
