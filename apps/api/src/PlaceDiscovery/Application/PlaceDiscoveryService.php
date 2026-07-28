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
    public function __construct(private Connection $connection, private PlaceCommandHandler $places, private DuplicatePlaceLookup $duplicates, private PlaceNormalizer $normalizer, private CandidateAuditTrail $audit)
    {
    }

    /** @return 'inserted'|'refreshed'|'linked' */
    public function import(string $runId, ProviderPlace $source, NormalizedPlace $normalized, DiscoveryClassification $classification): string
    {
        $snapshot = json_encode($source->snapshot, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (\strlen($snapshot) > 32_768) {
            throw new \DomainException('Bounded source snapshot exceeds 32 KiB.');
        }
        $hash = hash('sha256', $snapshot);
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $this->connection->transactional(function () use ($runId, $source, $normalized, $classification, $snapshot, $hash, $now): string {
            $values = $this->values($runId, $source, $normalized, $classification, $snapshot, $hash, $now);
            $candidateId = Uuid::v7()->toRfc4122();
            $insert = ['id' => $candidateId, 'first_seen_at' => $now, 'created_at' => $now, ...$values];
            $columns = array_keys($insert);
            $insertedId = $this->connection->fetchOne(\sprintf('INSERT INTO place_candidates (%s) VALUES (%s) ON CONFLICT (source, external_id) DO NOTHING RETURNING id', implode(', ', $columns), implode(', ', array_fill(0, \count($columns), '?'))), array_values($insert));
            $inserted = false !== $insertedId;
            $existing = $this->connection->fetchAssociative('SELECT id, status, manually_edited_at, source_payload_hash, source_closed_review_required FROM place_candidates WHERE source = ? AND external_id = ? FOR UPDATE', ['overture', $source->externalId]);
            if (false === $existing) {
                throw new \RuntimeException('Candidate upsert did not produce a lockable row.');
            }
            $candidateId = (string) $existing['id'];
            $link = $this->connection->fetchAssociative('SELECT id, place_id FROM place_source_links WHERE source = ? AND external_id = ? FOR UPDATE', ['overture', $source->externalId]);
            if (false !== $link) {
                $provenance = $this->provenance($source);
                $this->connection->executeStatement('UPDATE place_source_links SET source_release = ?, last_seen_at = ?, last_payload_hash = ?, source_provenance = ?::jsonb WHERE id = ?', [$source->release, $now, $hash, $provenance, $link['id']]);
                $changedAfterEdit = null !== $existing['manually_edited_at'] && $existing['source_payload_hash'] !== $hash;
                $closed = 'closed_permanently' === $source->operatingStatus;
                $newlyClosed = $closed && !(bool) $existing['source_closed_review_required'];
                $reopened = !$closed && (bool) $existing['source_closed_review_required'];
                $this->connection->executeStatement("UPDATE place_candidates SET discovery_run_id = ?, source_release = ?, source_record_version = ?, source_payload_hash = ?, source_snapshot = ?::jsonb, source_provenance = ?::jsonb, operating_status = ?, last_seen_at = ?, updated_at = ?, approved_place_id = COALESCE(approved_place_id, ?), status = 'APPROVED', source_changed_after_edit = source_changed_after_edit OR ?, source_closed_review_required = ? WHERE id = ?", [$runId, $source->release, $source->recordVersion, $hash, $snapshot, $provenance, $source->operatingStatus, $now, $now, $link['place_id'], $changedAfterEdit ? 'true' : 'false', $closed ? 'true' : 'false', $candidateId]);
                $action = $newlyClosed ? 'CLOSURE_REVIEW_FLAGGED' : ($reopened ? 'SOURCE_REOPENED' : 'SOURCE_REFRESHED');
                $this->audit->append($candidateId, 'SYSTEM', $action, (string) $existing['status'], 'APPROVED', ['discovery_run_id', 'source_release', 'source_record_version', 'source_payload_hash', 'source_snapshot', 'source_provenance', 'operating_status', 'last_seen_at', 'updated_at', 'approved_place_id', 'status', 'source_changed_after_edit', 'source_closed_review_required'], $newlyClosed ? 'Source reports permanently closed; public Place was not changed.' : null, $runId, null, $source->release);

                return 'linked';
            }
            if ($inserted) {
                $this->audit->append($candidateId, 'SYSTEM', 'DISCOVERED', null, (string) $values['status'], array_keys($values), null, $runId, null, $source->release);
                if (CandidateStatus::POSSIBLE_DUPLICATE->value === $values['status']) {
                    $this->audit->append($candidateId, 'SYSTEM', 'DUPLICATE_WARNING', null, CandidateStatus::POSSIBLE_DUPLICATE->value, ['duplicate_score', 'duplicate_reasons', 'possible_duplicate_place_id', 'possible_duplicate_candidate_ids'], null, $runId, null, $source->release);
                }

                return 'inserted';
            }
            $protected = \in_array($existing['status'], [CandidateStatus::APPROVED->value, CandidateStatus::REJECTED->value, CandidateStatus::DUPLICATE->value], true);
            if (!$protected && null === $existing['manually_edited_at']) {
                $this->connection->update('place_candidates', $values, ['id' => $existing['id']]);
            } else {
                $this->connection->update('place_candidates', ['discovery_run_id' => $runId, 'source_release' => $source->release, 'source_record_version' => $source->recordVersion, 'source_payload_hash' => $hash, 'source_snapshot' => $snapshot, 'source_provenance' => $this->provenance($source), 'operating_status' => $source->operatingStatus, 'last_seen_at' => $now, 'updated_at' => $now, 'source_changed_after_edit' => null !== $existing['manually_edited_at'] ? 'true' : 'false', 'source_closed_review_required' => CandidateStatus::APPROVED->value === $existing['status'] && 'closed_permanently' === $source->operatingStatus ? 'true' : 'false'], ['id' => $existing['id']]);
            }
            $changedFields = !$protected && null === $existing['manually_edited_at'] ? array_keys($values) : ['discovery_run_id', 'source_release', 'source_record_version', 'source_payload_hash', 'source_snapshot', 'source_provenance', 'operating_status', 'last_seen_at', 'updated_at', 'source_changed_after_edit', 'source_closed_review_required'];
            $this->audit->append($candidateId, 'SYSTEM', 'SOURCE_REFRESHED', (string) $existing['status'], $protected || null !== $existing['manually_edited_at'] ? (string) $existing['status'] : (string) $values['status'], $changedFields, null, $runId, null, $source->release);
            if (!$protected && CandidateStatus::POSSIBLE_DUPLICATE->value === $values['status'] && CandidateStatus::POSSIBLE_DUPLICATE->value !== $existing['status']) {
                $this->audit->append($candidateId, 'SYSTEM', 'DUPLICATE_WARNING', (string) $existing['status'], CandidateStatus::POSSIBLE_DUPLICATE->value, ['duplicate_score', 'duplicate_reasons', 'possible_duplicate_place_id', 'possible_duplicate_candidate_ids'], null, $runId, null, $source->release);
            }

            return 'refreshed';
        });
    }

    public function approve(string $candidateId, int $expectedVersion, string $reviewer): string
    {
        return $this->connection->transactional(function () use ($candidateId, $expectedVersion, $reviewer): string {
            $candidate = $this->connection->fetchAssociative(<<<'SQL'
SELECT c.*, category.slug AS category_slug, city.slug AS city_slug, city.country_code AS city_country_code, city.timezone AS city_timezone
FROM place_candidates c
LEFT JOIN categories category ON category.id = c.suggested_place_category_id
LEFT JOIN cities city ON city.id = c.suggested_city_id AND city.enabled = true
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
            foreach (['indoor', 'outdoor', 'free_entry'] as $requiredBoolean) {
                if (null === $candidate[$requiredBoolean]) {
                    throw new \DomainException('Candidate is missing reviewed Place attribute: '.$requiredBoolean);
                }
            }
            if ($candidate['city_country_code'] !== $candidate['country_code']) {
                throw new \DomainException('Selected City country does not match the candidate country.');
            }
            $provenance = json_decode((string) $candidate['source_provenance'], true, 16, \JSON_THROW_ON_ERROR);
            if (!\is_array($provenance) || [] === $provenance || array_filter($provenance, static fn (mixed $item): bool => !\is_array($item) || !isset($item['license']) || !\is_string($item['license']) || '' === trim($item['license']))) {
                throw new \DomainException('Candidate source licensing must be reviewed and resolved before approval.');
            }
            if (!(bool) $candidate['indoor'] && !(bool) $candidate['outdoor']) {
                throw new \DomainException('At least one of indoor or outdoor must be selected.');
            }
            if (false !== $this->connection->fetchOne('SELECT 1 FROM place_source_links WHERE source = ? AND external_id = ?', [$candidate['source'], $candidate['external_id']])) {
                throw new \DomainException('This source identity is already linked to a Place.');
            }
            $slugBase = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower((string) $candidate['name'])) ?: 'place') ?? 'place';
            $slug = mb_substr(trim($slugBase, '-'), 0, 70).'-'.substr(str_replace('-', '', $candidateId), 0, 8);
            $placeId = $this->places->create(new CreatePlaceDraft((string) $candidate['name'], $slug, 'Kandydat zatwierdzony przez administratora.', 'Miejsce utworzone jako szkic z danych Overture Maps. Wymaga uzupełnienia i publikacji w osobnym kroku.', (string) $candidate['address_line1'], (string) $candidate['postal_code'], (string) $candidate['city_slug'], (string) $candidate['country_code'], (float) $candidate['latitude'], (float) $candidate['longitude'], (string) $candidate['city_timezone'], (string) $candidate['category_slug'], (bool) $candidate['indoor'], (bool) $candidate['outdoor'], (bool) $candidate['free_entry'], websiteUrl: $candidate['website'], phone: $candidate['phone'], externalReferences: [new ExternalReferenceInput('overture', (string) $candidate['external_id'], null)]));
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $this->connection->executeStatement('INSERT INTO place_source_links (id, place_id, source, external_id, source_release, first_linked_at, last_seen_at, last_payload_hash, source_provenance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT (source, external_id) DO NOTHING', [Uuid::v7()->toRfc4122(), $placeId, $candidate['source'], $candidate['external_id'], $candidate['source_release'], $now, $now, $candidate['source_payload_hash'], $candidate['source_provenance']]);
            $changed = $this->connection->executeStatement('UPDATE place_candidates SET status = ?, approved_place_id = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?', [CandidateStatus::APPROVED->value, $placeId, $reviewer, $now, $now, $candidateId, $expectedVersion]);
            if (1 !== $changed) {
                throw new ConcurrentCandidateModification();
            }
            $this->audit->append($candidateId, 'ADMIN', 'APPROVED', (string) $candidate['status'], CandidateStatus::APPROVED->value, ['status', 'approved_place_id', 'reviewed_by', 'reviewed_at'], null, null, $reviewer, (string) $candidate['source_release']);

            return $placeId;
        });
    }

    public function reject(string $id, int $version, string $reviewer, string $reason): void
    {
        if ('' === trim($reason)) {
            throw new \DomainException('A rejection reason is required.');
        }
        $this->transition($id, $version, $reviewer, CandidateStatus::REJECTED, ['PENDING', 'NEEDS_MAPPING', 'POSSIBLE_DUPLICATE'], ['rejection_reason' => trim($reason)], 'REJECTED', trim($reason));
    }

    public function markDuplicate(string $id, int $version, string $reviewer, string $placeId): void
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM places WHERE id = ?', [$placeId])) {
            throw new \DomainException('Duplicate Place does not exist.');
        }
        $this->transition($id, $version, $reviewer, CandidateStatus::DUPLICATE, ['PENDING', 'POSSIBLE_DUPLICATE'], ['possible_duplicate_place_id' => $placeId], 'DUPLICATE_MARKED');
    }

    /** @param array<string, mixed> $draft */
    public function editCandidate(string $id, int $version, array $draft, ?string $reviewer = null): void
    {
        $name = trim((string) ($draft['name'] ?? ''));
        $latitude = filter_var($draft['latitude'] ?? null, \FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($draft['longitude'] ?? null, \FILTER_VALIDATE_FLOAT);
        $country = strtoupper(trim((string) ($draft['country_code'] ?? '')));
        $categoryId = trim((string) ($draft['category_id'] ?? ''));
        $cityId = trim((string) ($draft['city_id'] ?? ''));
        if ('' === $name || false === $latitude || false === $longitude || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \DomainException('Candidate draft has invalid name, coordinates, or country.');
        }
        if ('' !== $categoryId && false === $this->connection->fetchOne('SELECT 1 FROM categories WHERE id = ? AND enabled = true', [$categoryId])) {
            throw new \DomainException('Selected FamilyPlaces category does not exist.');
        }
        if ('' !== $cityId && false === $this->connection->fetchOne('SELECT 1 FROM cities WHERE id = ? AND enabled = true AND country_code = ?', [$cityId, $country])) {
            throw new \DomainException('Selected City does not exist or belongs to another country.');
        }
        $indoor = $this->nullableBoolean($draft, 'indoor');
        $outdoor = $this->nullableBoolean($draft, 'outdoor');
        $freeEntry = $this->nullableBoolean($draft, 'free_entry');
        if (null !== $indoor && null !== $outdoor && !$indoor && !$outdoor) {
            throw new \DomainException('At least one of indoor or outdoor must be selected.');
        }
        $website = trim((string) ($draft['website'] ?? '')) ?: null;
        $host = null;
        if (null !== $website) {
            $host = parse_url(str_contains($website, '://') ? $website : 'https://'.$website, \PHP_URL_HOST);
            $host = \is_string($host) ? preg_replace('/^www\./i', '', mb_strtolower($host)) : null;
        }
        $phone = trim((string) ($draft['phone'] ?? '')) ?: null;
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $before = $this->connection->fetchAssociative('SELECT status, source_release, suggested_place_category_id, suggested_city_id FROM place_candidates WHERE id = ?', [$id]);
        $changed = $this->connection->executeStatement("UPDATE place_candidates SET name = ?, normalized_name = ?, address_line1 = ?, postal_code = ?, locality = ?, country_code = ?, latitude = ?, longitude = ?, website = ?, normalized_website_host = ?, phone = ?, normalized_phone = ?, suggested_place_category_id = NULLIF(?, '')::uuid, suggested_city_id = NULLIF(?, '')::uuid, city_selection_source = CASE WHEN NULLIF(?, '') IS NULL THEN NULL ELSE 'ADMIN' END, indoor = ?, outdoor = ?, free_entry = ?, status = CASE WHEN NULLIF(?, '') IS NULL THEN 'NEEDS_MAPPING' WHEN status = 'NEEDS_MAPPING' THEN 'PENDING' ELSE status END, manually_edited_at = ?, source_changed_after_edit = false, updated_at = ?, version = version + 1 WHERE id = ? AND version = ? AND status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE')", [$name, $this->normalizer->comparison($name), trim((string) ($draft['address_line1'] ?? '')) ?: null, trim((string) ($draft['postal_code'] ?? '')) ?: null, trim((string) ($draft['locality'] ?? '')) ?: null, $country, $latitude, $longitude, $website, $host, $phone, null === $phone ? null : preg_replace('/(?!^\+)\D+/', '', $phone), $categoryId, $cityId, $cityId, $this->databaseBoolean($indoor), $this->databaseBoolean($outdoor), $this->databaseBoolean($freeEntry), $categoryId, $now, $now, $id, $version]);
        if (1 !== $changed) {
            throw new ConcurrentCandidateModification();
        }
        $this->audit->append($id, 'ADMIN', 'MANUAL_EDIT', false === $before ? null : (string) $before['status'], false === $before ? null : (string) $before['status'], ['name', 'address_line1', 'postal_code', 'locality', 'country_code', 'latitude', 'longitude', 'website', 'phone', 'suggested_place_category_id', 'suggested_city_id', 'indoor', 'outdoor', 'free_entry'], null, null, $reviewer, false === $before ? null : (string) $before['source_release']);
        if (false !== $before && ($before['suggested_place_category_id'] !== ('' === $categoryId ? null : $categoryId) || $before['suggested_city_id'] !== ('' === $cityId ? null : $cityId))) {
            $this->audit->append($id, 'ADMIN', 'MAPPING_CHANGED', (string) $before['status'], (string) $before['status'], ['suggested_place_category_id', 'suggested_city_id'], null, null, $reviewer, (string) $before['source_release']);
        }
    }

    public function refreshCandidateFromSource(string $id, int $version, ?string $reviewer = null): void
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
        $this->audit->append($id, 'ADMIN', 'SOURCE_FIELDS_RESTORED', null, null, ['name', 'address_line1', 'postal_code', 'locality', 'country_code', 'website', 'phone', 'manually_edited_at', 'source_changed_after_edit'], null, null, $reviewer);
    }

    public function resolveUnlicensedProvenance(string $id, int $version, string $license, string $reviewer): void
    {
        $license = trim($license);
        if ('' === $license || \strlen($license) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.+:\/() _-]{0,254}$/', $license)) {
            throw new \DomainException('A bounded reviewed license identifier is required.');
        }
        $this->connection->transactional(function () use ($id, $version, $license, $reviewer): void {
            $candidate = $this->connection->fetchAssociative("SELECT status, source_release, source_provenance FROM place_candidates WHERE id = ? AND version = ? AND status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE') FOR UPDATE", [$id, $version]);
            if (false === $candidate) {
                throw new ConcurrentCandidateModification();
            }
            $provenance = json_decode((string) $candidate['source_provenance'], true, 16, \JSON_THROW_ON_ERROR);
            if (!\is_array($provenance) || [] === $provenance) {
                throw new \DomainException('Candidate has no source provenance to resolve.');
            }
            $resolved = false;
            foreach ($provenance as &$item) {
                if (\is_array($item) && (!isset($item['license']) || !\is_string($item['license']) || '' === trim($item['license']))) {
                    $item['license'] = $license;
                    $resolved = true;
                }
            }
            unset($item);
            if (!$resolved) {
                throw new \DomainException('Candidate has no unresolved source license.');
            }
            $encoded = json_encode($provenance, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (\strlen($encoded) > 16_384) {
                throw new \DomainException('Bounded source provenance exceeds 16 KiB.');
            }
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            if (1 !== $this->connection->executeStatement('UPDATE place_candidates SET source_provenance = ?::jsonb, reviewed_by = ?, reviewed_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?', [$encoded, $reviewer, $now, $now, $id, $version])) {
                throw new ConcurrentCandidateModification();
            }
            $this->audit->append($id, 'ADMIN', 'SOURCE_LICENSE_RESOLVED', (string) $candidate['status'], (string) $candidate['status'], ['source_provenance', 'reviewed_by', 'reviewed_at', 'updated_at', 'version'], 'Unresolved source license reviewed as '.$license.'.', null, $reviewer, (string) $candidate['source_release']);
        });
    }

    public function clearDuplicate(string $id, int $version, string $reviewer): void
    {
        $this->connection->transactional(function () use ($id, $version, $reviewer): void {
            $candidate = $this->connection->fetchAssociative('SELECT status, source_release, suggested_place_category_id FROM place_candidates WHERE id = ? FOR UPDATE', [$id]);
            $next = false !== $candidate && null === $candidate['suggested_place_category_id'] ? CandidateStatus::NEEDS_MAPPING->value : CandidateStatus::PENDING->value;
            if (false === $candidate || 1 !== $this->connection->executeStatement("UPDATE place_candidates SET status = ?, duplicate_score = NULL, duplicate_reasons = NULL, possible_duplicate_place_id = NULL, possible_duplicate_candidate_ids = '[]'::jsonb, updated_at = ?, version = version + 1 WHERE id = ? AND version = ? AND status = 'POSSIBLE_DUPLICATE'", [$next, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), $id, $version])) {
                throw new ConcurrentCandidateModification();
            }
            $this->audit->append($id, 'ADMIN', 'DUPLICATE_CLEARED', (string) $candidate['status'], $next, ['status', 'duplicate_score', 'duplicate_reasons', 'possible_duplicate_place_id', 'possible_duplicate_candidate_ids'], null, null, $reviewer, (string) $candidate['source_release']);
        });
    }

    /**
     * @param list<string>               $allowed
     * @param array<string, scalar|null> $extra
     */
    private function transition(string $id, int $version, string $reviewer, CandidateStatus $next, array $allowed, array $extra, string $action, ?string $reason = null): void
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
        $this->connection->transactional(function () use ($id, $reviewer, $next, $set, $params, $allowedPlaceholders, $extra, $action, $reason): void {
            $before = $this->connection->fetchAssociative('SELECT status, source_release FROM place_candidates WHERE id = ? FOR UPDATE', [$id]);
            if (false === $before || 1 !== $this->connection->executeStatement('UPDATE place_candidates SET '.$set.' WHERE id = ? AND version = ? AND status IN ('.$allowedPlaceholders.')', $params)) {
                throw new ConcurrentCandidateModification();
            }
            $this->audit->append($id, 'ADMIN', $action, (string) $before['status'], $next->value, ['status', 'reviewed_by', 'reviewed_at', ...array_keys($extra)], $reason, null, $reviewer, (string) $before['source_release']);
        });
    }

    /** @return array<string, mixed> */
    private function values(string $runId, ProviderPlace $source, NormalizedPlace $normalized, DiscoveryClassification $classification, string $snapshot, string $hash, string $now): array
    {
        $categoryId = null === $classification->category ? null : $this->connection->fetchOne('SELECT id FROM categories WHERE slug = ? AND enabled = true', [$classification->category]);
        $duplicate = $this->duplicates->assess($source, $normalized);
        $cityRows = [];
        if (null !== $source->locality && null !== $source->countryCode) {
            $cityRows = $this->connection->fetchAllAssociative('SELECT id FROM cities WHERE enabled = true AND country_code = ? AND lower(name) = lower(?) ORDER BY id LIMIT 2', [$source->countryCode, $source->locality]);
        }
        $cityId = 1 === \count($cityRows) ? (string) $cityRows[0]['id'] : null;
        $status = 'closed_permanently' === $source->operatingStatus ? CandidateStatus::STALE->value : ([] === $source->provenance ? CandidateStatus::NEEDS_MAPPING->value : ($duplicate->score >= 70 ? CandidateStatus::POSSIBLE_DUPLICATE->value : (false === $categoryId ? CandidateStatus::NEEDS_MAPPING->value : $classification->status->value)));
        $discoveryReasons = [] === $source->provenance ? [...$classification->reasons, 'missing_source_provenance'] : $classification->reasons;

        return ['discovery_run_id' => $runId, 'source' => 'overture', 'external_id' => $source->externalId, 'source_release' => $source->release, 'source_record_version' => $source->recordVersion, 'source_payload_hash' => $hash, 'source_snapshot' => $snapshot, 'source_provenance' => $this->provenance($source), 'name' => $normalized->name, 'normalized_name' => $normalized->normalizedName, 'address_line1' => $source->addressLine1, 'postal_code' => $source->postalCode, 'locality' => $source->locality, 'country_code' => $source->countryCode, 'latitude' => $source->latitude, 'longitude' => $source->longitude, 'website' => $source->website, 'normalized_website_host' => $normalized->websiteHost, 'phone' => $source->phone, 'normalized_phone' => $normalized->phone, 'source_categories' => json_encode($source->categories, \JSON_THROW_ON_ERROR), 'suggested_place_category_id' => false === $categoryId ? null : $categoryId, 'suggested_city_id' => $cityId, 'city_selection_source' => null === $cityId ? null : 'AUTO', 'confidence' => $source->confidence, 'operating_status' => $source->operatingStatus, 'discovery_score' => $classification->score, 'discovery_reasons' => json_encode($discoveryReasons, \JSON_THROW_ON_ERROR), 'duplicate_score' => 0 === $duplicate->score ? null : $duplicate->score, 'duplicate_reasons' => [] === $duplicate->reasons ? null : json_encode($duplicate->reasons, \JSON_THROW_ON_ERROR), 'possible_duplicate_place_id' => $duplicate->placeIds[0] ?? null, 'possible_duplicate_candidate_ids' => json_encode($duplicate->candidateIds, \JSON_THROW_ON_ERROR), 'status' => $status, 'last_seen_at' => $now, 'updated_at' => $now];
    }

    private function provenance(ProviderPlace $source): string
    {
        $json = json_encode($source->provenance, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > 16_384) {
            throw new \DomainException('Bounded source provenance exceeds 16 KiB.');
        }

        return $json;
    }

    /** @param array<string, mixed> $draft */
    private function nullableBoolean(array $draft, string $key): ?bool
    {
        if (!\array_key_exists($key, $draft) || '' === (string) $draft[$key]) {
            return null;
        }
        $value = filter_var($draft[$key], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
        if (null === $value) {
            throw new \DomainException('Invalid reviewed boolean: '.$key);
        }

        return $value;
    }

    private function databaseBoolean(?bool $value): ?string
    {
        return null === $value ? null : ($value ? 'true' : 'false');
    }
}

final class ConcurrentCandidateModification extends \DomainException
{
}
