<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\Port\DuplicatePlaceLookup;
use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;
use App\PlaceDiscovery\Domain\DiscoveryClassification;
use App\PlaceDiscovery\Domain\NormalizedPlace;
use App\PlaceDiscovery\Domain\OvertureOperatingStatus;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\PlaceDiscovery\Domain\SourceLicenseResolution;
use App\PlaceDiscovery\Domain\SourceProvenanceFingerprint;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\PlaceCommandHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PlaceDiscoveryService
{
    // 16 KiB raw provenance plus metadata for all 32 bounded reviewed sources fits below 128 KiB.
    public const SOURCE_LICENSE_RESOLUTIONS_MAX_BYTES = 131_072;

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
            $existing = $this->connection->fetchAssociative('SELECT id, status, version, manually_edited_at, source_payload_hash, source_closed_review_required, source_license_resolutions FROM place_candidates WHERE source = ? AND external_id = ? FOR UPDATE', ['overture', $source->externalId]);
            if (false === $existing) {
                throw new \RuntimeException('Candidate upsert did not produce a lockable row.');
            }
            $candidateId = (string) $existing['id'];
            $licenseState = $this->licenseState($this->decodeProvenance((string) $values['source_provenance']), $this->decodeResolutions((string) $existing['source_license_resolutions']));
            $values['source_license_resolutions'] = $this->encodeResolutions($licenseState['resolutions']);
            $values['source_license_review_required'] = $licenseState['unresolved'] ? 'true' : 'false';
            $values['version'] = (int) $existing['version'] + 1;
            $link = $this->connection->fetchAssociative('SELECT id, place_id FROM place_source_links WHERE source = ? AND external_id = ? FOR UPDATE', ['overture', $source->externalId]);
            if (false !== $link) {
                $this->connection->update('place_source_links', ['last_seen_at' => $now], ['id' => $link['id']]);
                if (!$licenseState['unresolved']) {
                    $this->connection->executeStatement('UPDATE place_source_links SET source_release = ?, last_payload_hash = ?, source_provenance = ?::jsonb WHERE id = ?', [$source->release, $hash, $this->encodeProvenance($licenseState['effective']), $link['id']]);
                }
                $changedAfterEdit = null !== $existing['manually_edited_at'] && $existing['source_payload_hash'] !== $hash;
                $operatingStatus = OvertureOperatingStatus::normalize($source->operatingStatus);
                $closed = OvertureOperatingStatus::PERMANENTLY_CLOSED === $operatingStatus;
                $newlyClosed = $closed && !(bool) $existing['source_closed_review_required'];
                $reopened = true === $operatingStatus?->clearsPermanentClosureReview() && (bool) $existing['source_closed_review_required'];
                $closureReviewRequired = $closed || (!$reopened && (bool) $existing['source_closed_review_required']);
                $this->connection->executeStatement("UPDATE place_candidates SET discovery_run_id = ?, source_release = ?, source_record_version = ?, source_payload_hash = ?, source_snapshot = ?::jsonb, source_provenance = ?::jsonb, source_license_resolutions = ?::jsonb, source_license_review_required = ?, operating_status = ?, last_seen_at = ?, updated_at = ?, approved_place_id = COALESCE(approved_place_id, ?), status = 'APPROVED', source_changed_after_edit = source_changed_after_edit OR ?, source_closed_review_required = ?, version = version + 1 WHERE id = ?", [$runId, $source->release, $source->recordVersion, $hash, $snapshot, $values['source_provenance'], $values['source_license_resolutions'], $values['source_license_review_required'], $source->operatingStatus, $now, $now, $link['place_id'], $changedAfterEdit ? 'true' : 'false', $closureReviewRequired ? 'true' : 'false', $candidateId]);
                $action = $newlyClosed ? 'CLOSURE_REVIEW_FLAGGED' : ($reopened ? 'SOURCE_REOPENED' : 'SOURCE_REFRESHED');
                $this->audit->append($candidateId, 'SYSTEM', $action, (string) $existing['status'], 'APPROVED', ['discovery_run_id', 'source_release', 'source_record_version', 'source_payload_hash', 'source_snapshot', 'source_provenance', 'source_license_resolutions', 'source_license_review_required', 'operating_status', 'last_seen_at', 'updated_at', 'approved_place_id', 'status', 'source_changed_after_edit', 'source_closed_review_required', 'version'], $newlyClosed ? 'Source reports permanently closed; public Place was not changed.' : null, $runId, null, $source->release);
                $this->auditRemovedResolutions($candidateId, $licenseState['removed'], $runId, $source->release);
                if (!$licenseState['unresolved']) {
                    $this->audit->append($candidateId, 'SYSTEM', 'SOURCE_LINK_PROVENANCE_UPDATED', 'APPROVED', 'APPROVED', ['place_source_links.source_release', 'place_source_links.last_payload_hash', 'place_source_links.source_provenance'], 'Last compliant reviewed source state advanced to the latest observed provider state.', $runId, null, $source->release);
                }

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
                $operatingStatus = OvertureOperatingStatus::normalize($source->operatingStatus);
                $closureReviewRequired = CandidateStatus::APPROVED->value === $existing['status'] && (OvertureOperatingStatus::PERMANENTLY_CLOSED === $operatingStatus || (true !== $operatingStatus?->clearsPermanentClosureReview() && (bool) $existing['source_closed_review_required']));
                $this->connection->update('place_candidates', ['discovery_run_id' => $runId, 'source_release' => $source->release, 'source_record_version' => $source->recordVersion, 'source_payload_hash' => $hash, 'source_snapshot' => $snapshot, 'source_provenance' => $values['source_provenance'], 'source_license_resolutions' => $values['source_license_resolutions'], 'source_license_review_required' => $values['source_license_review_required'], 'operating_status' => $source->operatingStatus, 'last_seen_at' => $now, 'updated_at' => $now, 'source_changed_after_edit' => null !== $existing['manually_edited_at'] ? 'true' : 'false', 'source_closed_review_required' => $closureReviewRequired ? 'true' : 'false', 'version' => $values['version']], ['id' => $existing['id']]);
            }
            $changedFields = !$protected && null === $existing['manually_edited_at'] ? array_keys($values) : ['discovery_run_id', 'source_release', 'source_record_version', 'source_payload_hash', 'source_snapshot', 'source_provenance', 'source_license_resolutions', 'source_license_review_required', 'operating_status', 'last_seen_at', 'updated_at', 'source_changed_after_edit', 'source_closed_review_required', 'version'];
            $this->audit->append($candidateId, 'SYSTEM', 'SOURCE_REFRESHED', (string) $existing['status'], $protected || null !== $existing['manually_edited_at'] ? (string) $existing['status'] : (string) $values['status'], $changedFields, null, $runId, null, $source->release);
            $this->auditRemovedResolutions($candidateId, $licenseState['removed'], $runId, $source->release);
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
            $licenseState = $this->licenseState($this->decodeProvenance((string) $candidate['source_provenance']), $this->decodeResolutions((string) $candidate['source_license_resolutions']));
            if ([] === $licenseState['effective'] || $licenseState['unresolved']) {
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
            $this->connection->executeStatement('INSERT INTO place_source_links (id, place_id, source, external_id, source_release, first_linked_at, last_seen_at, last_payload_hash, source_provenance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT (source, external_id) DO NOTHING', [Uuid::v7()->toRfc4122(), $placeId, $candidate['source'], $candidate['external_id'], $candidate['source_release'], $now, $now, $candidate['source_payload_hash'], $this->encodeProvenance($licenseState['effective'])]);
            $changed = $this->connection->executeStatement('UPDATE place_candidates SET status = ?, approved_place_id = ?, source_license_review_required = false, reviewed_by = ?, reviewed_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?', [CandidateStatus::APPROVED->value, $placeId, $reviewer, $now, $now, $candidateId, $expectedVersion]);
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

    public function resolveUnlicensedProvenance(string $id, int $version, string $fingerprint, string $license, string $reviewer): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new \DomainException('A valid source provenance fingerprint is required.');
        }
        $license = trim($license);
        if ('' === $license || \strlen($license) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.+:\/() _-]{0,254}$/', $license)) {
            throw new \DomainException('A bounded reviewed license identifier is required.');
        }
        $this->connection->transactional(function () use ($id, $version, $fingerprint, $license, $reviewer): void {
            $candidate = $this->connection->fetchAssociative("SELECT status, source, external_id, source_release, source_payload_hash, source_provenance, source_license_resolutions, source_license_review_required FROM place_candidates WHERE id = ? AND version = ? AND status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE','APPROVED') FOR UPDATE", [$id, $version]);
            if (false === $candidate) {
                throw new ConcurrentCandidateModification();
            }
            $provenance = $this->decodeProvenance((string) $candidate['source_provenance']);
            if ([] === $provenance) {
                throw new \DomainException('Candidate has no source provenance to resolve.');
            }
            $matches = [];
            foreach ($provenance as $index => $item) {
                if (\is_array($item) && hash_equals($fingerprint, SourceProvenanceFingerprint::fromArray($item))) {
                    $matches[] = $index;
                }
            }
            if ([] === $matches) {
                throw new \DomainException('Source provenance selector is stale or does not match this candidate.');
            }
            if (1 !== \count($matches)) {
                throw new \DomainException('Source provenance selector is ambiguous.');
            }
            $index = $matches[0];
            $selected = $provenance[$index];
            if (isset($selected['license']) && \is_string($selected['license']) && '' !== trim($selected['license'])) {
                throw new \DomainException('Provider-supplied source license cannot be overwritten in this workflow.');
            }
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $resolutions = $this->decodeResolutions((string) $candidate['source_license_resolutions']);
            $resolution = SourceLicenseResolution::review($selected, $license, $reviewer, $now, (string) $candidate['source_release']);
            $resolutions[$fingerprint] = $resolution;
            $licenseState = $this->licenseState($provenance, $resolutions);
            $encoded = $this->encodeResolutions($licenseState['resolutions']);
            if (1 !== $this->connection->executeStatement('UPDATE place_candidates SET source_license_resolutions = ?::jsonb, source_license_review_required = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?', [$encoded, $licenseState['unresolved'] ? 'true' : 'false', $reviewer, $now, $now, $id, $version])) {
                throw new ConcurrentCandidateModification();
            }
            if (CandidateStatus::APPROVED->value === $candidate['status'] && !$licenseState['unresolved']) {
                $link = $this->connection->fetchAssociative('SELECT id FROM place_source_links WHERE source = ? AND external_id = ? FOR UPDATE', [$candidate['source'], $candidate['external_id']]);
                if (false === $link) {
                    throw new \DomainException('Approved candidate has no source link.');
                }
                $this->connection->executeStatement('UPDATE place_source_links SET source_release = ?, last_payload_hash = ?, source_provenance = ?::jsonb WHERE id = ?', [$candidate['source_release'], $candidate['source_payload_hash'], $this->encodeProvenance($licenseState['effective']), $link['id']]);
                $this->audit->append($id, 'ADMIN', 'SOURCE_LINK_PROVENANCE_UPDATED', (string) $candidate['status'], (string) $candidate['status'], ['place_source_links.source_release', 'place_source_links.last_payload_hash', 'place_source_links.source_provenance', 'source_license_review_required'], 'Final reviewed source resolution advanced the last compliant source state.', null, $reviewer, (string) $candidate['source_release']);
            }
            $auditIdentity = ['dataset' => $selected['dataset'], 'property' => $selected['property'], 'fingerprint' => $fingerprint, 'license' => $license];
            $this->audit->append($id, 'ADMIN', 'SOURCE_LICENSE_RESOLVED', (string) $candidate['status'], (string) $candidate['status'], ['source_license_resolutions', 'source_license_review_required', 'reviewed_by', 'reviewed_at', 'updated_at', 'version'], null, null, $reviewer, (string) $candidate['source_release'], null, $auditIdentity);
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
        $status = OvertureOperatingStatus::PERMANENTLY_CLOSED === OvertureOperatingStatus::normalize($source->operatingStatus) ? CandidateStatus::STALE->value : ([] === $source->provenance ? CandidateStatus::NEEDS_MAPPING->value : ($duplicate->score >= 70 ? CandidateStatus::POSSIBLE_DUPLICATE->value : (false === $categoryId ? CandidateStatus::NEEDS_MAPPING->value : $classification->status->value)));
        $discoveryReasons = [] === $source->provenance ? [...$classification->reasons, 'missing_source_provenance'] : $classification->reasons;

        $provenance = $this->provenance($source);
        $rawProvenance = $this->decodeProvenance($provenance);

        return ['discovery_run_id' => $runId, 'source' => 'overture', 'external_id' => $source->externalId, 'source_release' => $source->release, 'source_record_version' => $source->recordVersion, 'source_payload_hash' => $hash, 'source_snapshot' => $snapshot, 'source_provenance' => $provenance, 'source_license_resolutions' => '{}', 'source_license_review_required' => $this->hasUnlicensedSource($rawProvenance) ? 'true' : 'false', 'name' => $normalized->name, 'normalized_name' => $normalized->normalizedName, 'address_line1' => $source->addressLine1, 'postal_code' => $source->postalCode, 'locality' => $source->locality, 'country_code' => $source->countryCode, 'latitude' => $source->latitude, 'longitude' => $source->longitude, 'website' => $source->website, 'normalized_website_host' => $normalized->websiteHost, 'phone' => $source->phone, 'normalized_phone' => $normalized->phone, 'source_categories' => json_encode($source->categories, \JSON_THROW_ON_ERROR), 'suggested_place_category_id' => false === $categoryId ? null : $categoryId, 'suggested_city_id' => $cityId, 'city_selection_source' => null === $cityId ? null : 'AUTO', 'confidence' => $source->confidence, 'operating_status' => $source->operatingStatus, 'discovery_score' => $classification->score, 'discovery_reasons' => json_encode($discoveryReasons, \JSON_THROW_ON_ERROR), 'duplicate_score' => 0 === $duplicate->score ? null : $duplicate->score, 'duplicate_reasons' => [] === $duplicate->reasons ? null : json_encode($duplicate->reasons, \JSON_THROW_ON_ERROR), 'possible_duplicate_place_id' => $duplicate->placeIds[0] ?? null, 'possible_duplicate_candidate_ids' => json_encode($duplicate->candidateIds, \JSON_THROW_ON_ERROR), 'status' => $status, 'last_seen_at' => $now, 'updated_at' => $now];
    }

    private function provenance(ProviderPlace $source): string
    {
        $json = json_encode($source->provenance, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > 16_384) {
            throw new \DomainException('Bounded source provenance exceeds 16 KiB.');
        }

        return $json;
    }

    /** @return list<array<string, mixed>> */
    private function decodeProvenance(string $json): array
    {
        $provenance = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($provenance) || !array_is_list($provenance)) {
            throw new \DomainException('Source provenance must be a JSON array.');
        }

        return $provenance;
    }

    /** @return array<string, SourceLicenseResolution> */
    private function decodeResolutions(string $json): array
    {
        $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw new \DomainException('Reviewed source license resolutions must be a JSON object.');
        }
        $resolutions = [];
        foreach ($data as $fingerprint => $resolution) {
            if (!\is_string($fingerprint) || !\is_array($resolution)) {
                throw new \DomainException('Reviewed source license resolution is malformed.');
            }
            $resolutions[$fingerprint] = SourceLicenseResolution::fromArray($fingerprint, $resolution);
        }

        return $resolutions;
    }

    /**
     * @param list<array<string, mixed>>             $provenance
     * @param array<string, SourceLicenseResolution> $resolutions
     *
     * @return array{effective: list<array<string, mixed>>, resolutions: array<string, SourceLicenseResolution>, unresolved: bool, removed: array<string, SourceLicenseResolution>}
     */
    private function licenseState(array $provenance, array $resolutions): array
    {
        $counts = [];
        $sourcesByFingerprint = [];
        foreach ($provenance as $source) {
            $fingerprint = SourceProvenanceFingerprint::fromArray($source);
            $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
            $sourcesByFingerprint[$fingerprint][] = $source;
        }
        $retained = [];
        $removed = [];
        foreach ($resolutions as $fingerprint => $resolution) {
            if (1 === ($counts[$fingerprint] ?? 0)) {
                $source = $sourcesByFingerprint[$fingerprint][0];
                if (!isset($source['license']) || !\is_string($source['license']) || '' === trim($source['license'])) {
                    $retained[$fingerprint] = $resolution;
                    continue;
                }
            }
            $removed[$fingerprint] = $resolution;
        }
        $effective = [];
        $unresolved = [] === $provenance;
        foreach ($provenance as $source) {
            $providerLicense = isset($source['license']) && \is_string($source['license']) && '' !== trim($source['license']);
            $resolution = $retained[SourceProvenanceFingerprint::fromArray($source)] ?? null;
            if (!$providerLicense && null !== $resolution) {
                $source['license'] = $resolution->license;
            } elseif (!$providerLicense) {
                $unresolved = true;
            }
            $effective[] = $source;
        }

        return ['effective' => $effective, 'resolutions' => $retained, 'unresolved' => $unresolved, 'removed' => $removed];
    }

    /** @param array<string, SourceLicenseResolution> $resolutions */
    private function encodeResolutions(array $resolutions): string
    {
        $json = json_encode((object) $resolutions, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ((int) $this->connection->fetchOne('SELECT octet_length(?::jsonb::text)', [$json]) > self::SOURCE_LICENSE_RESOLUTIONS_MAX_BYTES) {
            throw new \DomainException('Bounded reviewed source license resolutions exceed 128 KiB.');
        }

        return $json;
    }

    /** @param list<array<string, mixed>> $provenance */
    private function encodeProvenance(array $provenance): string
    {
        $json = json_encode($provenance, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > 16_384) {
            throw new \DomainException('Bounded effective source provenance exceeds 16 KiB.');
        }

        return $json;
    }

    /** @param list<array<string, mixed>> $provenance */
    private function hasUnlicensedSource(array $provenance): bool
    {
        return [] === $provenance || [] !== array_filter($provenance, static fn (array $source): bool => !isset($source['license']) || !\is_string($source['license']) || '' === trim($source['license']));
    }

    /** @param array<string, SourceLicenseResolution> $resolutions */
    private function auditRemovedResolutions(string $candidateId, array $resolutions, string $runId, string $sourceRelease): void
    {
        if ([] === $resolutions) {
            return;
        }
        foreach ($resolutions as $fingerprint => $resolution) {
            $details = [
                'fingerprint' => $fingerprint,
                'license' => $resolution->license,
                'reviewer' => $resolution->reviewer,
                'reviewed_at' => $resolution->reviewedAt,
                'reviewed_source_release' => $resolution->sourceRelease,
                'source_identity' => $resolution->sourceIdentity,
                'superseding_source_release' => $sourceRelease,
                'discovery_run_id' => $runId,
            ];
            $this->audit->append($candidateId, 'SYSTEM', 'SOURCE_LICENSE_RESOLUTION_STALE', null, null, ['source_license_resolutions', 'source_license_review_required'], 'Reviewed source identity no longer matches the latest provenance.', $runId, null, $sourceRelease, null, $details);
        }
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
