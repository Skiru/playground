<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\ConcurrentCandidateModification;
use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\OvertureOperatingStatus;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\PlaceDiscovery\Domain\ProviderSourceRecord;
use App\PlaceDiscovery\Domain\SourceProvenanceFingerprint;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApprovedSourceRefreshIntegrationTest extends KernelTestCase
{
    private const CANDIDATE = '00000000-0000-7000-8000-000000000902';
    private const RUN = '00000000-0000-7000-8000-000000000901';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::ensureKernelShutdown();
    }

    public function testApprovedSourceRefreshRetainsPublicPlaceAndCreatesIdempotentClosureReviewSignal(): void
    {
        $service = self::getContainer()->get(PlaceDiscoveryService::class);
        $normalizer = self::getContainer()->get(PlaceNormalizer::class);
        $profile = self::getContainer()->get(FamilyDiscoveryProfile::class);
        self::assertInstanceOf(PlaceDiscoveryService::class, $service);
        self::assertInstanceOf(PlaceNormalizer::class, $normalizer);
        self::assertInstanceOf(FamilyDiscoveryProfile::class, $profile);
        $placeId = $service->approve(self::CANDIDATE, 1, 'admin@example.test');
        $publicBefore = $this->connection->fetchAssociative('SELECT name,status,indoor,outdoor,free_entry,version FROM places WHERE id = ?', [$placeId]);
        self::assertIsArray($publicBefore);
        $this->connection->executeStatement('UPDATE place_candidates SET manually_edited_at = now() WHERE id = ?', [self::CANDIDATE]);

        $closed = $this->place('2026-08-20.0', OvertureOperatingStatus::PERMANENTLY_CLOSED->value, 'Changed provider name');
        $classification = $profile->classify($closed, $normalizer->normalize($closed));
        self::assertSame('linked', $service->import(self::RUN, $closed, $normalizer->normalize($closed), $classification));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT source_closed_review_required FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT source_changed_after_edit FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertSame('Apache-2.0', $this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_source_links WHERE external_id = 'gers-normal'"));
        self::assertSame($publicBefore, $this->connection->fetchAssociative('SELECT name,status,indoor,outdoor,free_entry,version FROM places WHERE id = ?', [$placeId]));

        $service->import(self::RUN, $closed, $normalizer->normalize($closed), $classification);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'CLOSURE_REVIEW_FLAGGED'", [self::CANDIDATE]));

        foreach ([null, 'future_provider_status'] as $unknownStatus) {
            $unknown = $this->place('2026-09-01.0', $unknownStatus, 'Unknown status provider name');
            $service->import(self::RUN, $unknown, $normalizer->normalize($unknown), $profile->classify($unknown, $normalizer->normalize($unknown)));
            self::assertTrue((bool) $this->connection->fetchOne('SELECT source_closed_review_required FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_REOPENED'", [self::CANDIDATE]));
        }

        $temporarilyClosed = $this->place('2026-09-10.0', OvertureOperatingStatus::TEMPORARILY_CLOSED->value, 'Temporarily closed provider name');
        $service->import(self::RUN, $temporarilyClosed, $normalizer->normalize($temporarilyClosed), $profile->classify($temporarilyClosed, $normalizer->normalize($temporarilyClosed)));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT source_closed_review_required FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_REOPENED'", [self::CANDIDATE]));

        $service->import(self::RUN, $closed, $normalizer->normalize($closed), $classification);
        $open = $this->place('2026-09-17.0', OvertureOperatingStatus::OPEN->value, 'Open provider name');
        $service->import(self::RUN, $open, $normalizer->normalize($open), $profile->classify($open, $normalizer->normalize($open)));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT source_closed_review_required FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_REOPENED'", [self::CANDIDATE]));
        self::assertSame($publicBefore, $this->connection->fetchAssociative('SELECT name,status,indoor,outdoor,free_entry,version FROM places WHERE id = ?', [$placeId]));
    }

    public function testManualDraftFieldsSurviveNewUnresolvedProvenanceUntilReviewedResolution(): void
    {
        $service = self::getContainer()->get(PlaceDiscoveryService::class);
        $normalizer = self::getContainer()->get(PlaceNormalizer::class);
        $profile = self::getContainer()->get(FamilyDiscoveryProfile::class);
        self::assertInstanceOf(PlaceDiscoveryService::class, $service);
        self::assertInstanceOf(PlaceNormalizer::class, $normalizer);
        self::assertInstanceOf(FamilyDiscoveryProfile::class, $profile);
        $service->editCandidate(self::CANDIDATE, 1, ['name' => 'Manual family name', 'address_line1' => 'Manual 7', 'postal_code' => '35-001', 'locality' => 'Manual locality', 'country_code' => 'PL', 'latitude' => '50.0413', 'longitude' => '21.999', 'category_id' => '00000000-0000-7000-8000-000000000201', 'city_id' => '00000000-0000-7000-8000-000000000105', 'indoor' => '1', 'outdoor' => '0', 'free_entry' => '1'], 'admin@example.test');
        $overtureSource = new ProviderSourceRecord('', 'Overture', null, provider: 'Overture Maps Foundation', resource: 'places', version: '3');
        $foursquareSource = new ProviderSourceRecord('/names/primary', 'Foursquare', null, 'fsq-1', provider: 'Foursquare', resource: 'places', version: '3');
        $source = new ProviderPlace('gers-normal', '2026-10-15.0', '3', 'New provider name', 50.2, 21.8, 'Provider address', '00-001', 'Provider locality', 'PL', null, null, ['playground'], 'playground', 0.96, OvertureOperatingStatus::PERMANENTLY_CLOSED->value, ['id' => 'gers-normal', 'name' => 'New provider name', 'operating_status' => OvertureOperatingStatus::PERMANENTLY_CLOSED->value], [$overtureSource, $foursquareSource]);
        self::assertSame('refreshed', $service->import(self::RUN, $source, $normalizer->normalize($source), $profile->classify($source, $normalizer->normalize($source))));
        $candidate = $this->connection->fetchAssociative('SELECT * FROM place_candidates WHERE id = ?', [self::CANDIDATE]);
        self::assertIsArray($candidate);
        self::assertSame(['Manual family name', 'Manual 7', 'Manual locality'], [$candidate['name'], $candidate['address_line1'], $candidate['locality']]);
        self::assertSame('2026-10-15.0', $candidate['source_release']);
        self::assertSame('3', $candidate['source_record_version']);
        self::assertSame(OvertureOperatingStatus::PERMANENTLY_CLOSED->value, $candidate['operating_status']);
        self::assertNull($this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
        try {
            $service->approve(self::CANDIDATE, 2, 'admin@example.test');
            self::fail('Unresolved current-release licensing must block approval.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('licensing', $exception->getMessage());
        }
        try {
            $service->resolveUnlicensedProvenance(self::CANDIDATE, 2, SourceProvenanceFingerprint::fromArray($overtureSource->jsonSerialize()), '<script>', 'admin@example.test');
            self::fail('Malformed reviewed license identifiers must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('bounded', $exception->getMessage());
        }
        $service->resolveUnlicensedProvenance(self::CANDIDATE, 2, SourceProvenanceFingerprint::fromArray($overtureSource->jsonSerialize()), 'Reviewed-Overture-1.0', 'admin@example.test');
        self::assertSame('Reviewed-Overture-1.0', $this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
        self::assertNull($this->connection->fetchOne("SELECT source_provenance->1->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
        try {
            $service->approve(self::CANDIDATE, 3, 'admin@example.test');
            self::fail('Every current source license must be resolved before approval.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('licensing', $exception->getMessage());
        }
        try {
            $service->resolveUnlicensedProvenance(self::CANDIDATE, 2, SourceProvenanceFingerprint::fromArray($foursquareSource->jsonSerialize()), 'Reviewed-Foursquare-1.0', 'admin@example.test');
            self::fail('A stale candidate version must be rejected.');
        } catch (\DomainException $exception) {
            self::assertInstanceOf(ConcurrentCandidateModification::class, $exception);
        }
        $service->resolveUnlicensedProvenance(self::CANDIDATE, 3, SourceProvenanceFingerprint::fromArray($foursquareSource->jsonSerialize()), 'Reviewed-Foursquare-1.0', 'admin@example.test');
        $placeId = $service->approve(self::CANDIDATE, 4, 'admin@example.test');
        self::assertSame('2026-10-15.0', $this->connection->fetchOne('SELECT source_release FROM place_source_links WHERE place_id = ?', [$placeId]));
        self::assertSame(['Reviewed-Overture-1.0', 'Reviewed-Foursquare-1.0'], $this->connection->fetchFirstColumn("SELECT jsonb_array_elements(source_provenance)->>'license' FROM place_source_links WHERE place_id = ?", [$placeId]));
        self::assertSame('SOURCE_LICENSE_RESOLVED', $this->connection->fetchOne("SELECT action FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_LICENSE_RESOLVED'", [self::CANDIDATE]));
        $auditReasons = $this->connection->fetchFirstColumn("SELECT reason FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_LICENSE_RESOLVED' ORDER BY created_at, id", [self::CANDIDATE]);
        self::assertStringContainsString(SourceProvenanceFingerprint::fromArray($overtureSource->jsonSerialize()), $auditReasons[0]);
        self::assertStringContainsString('Foursquare', $auditReasons[1]);
    }

    public function testLicenseSelectorRejectsStaleAmbiguousAndAlreadyLicensedSources(): void
    {
        $service = self::getContainer()->get(PlaceDiscoveryService::class);
        $normalizer = self::getContainer()->get(PlaceNormalizer::class);
        $profile = self::getContainer()->get(FamilyDiscoveryProfile::class);
        self::assertInstanceOf(PlaceDiscoveryService::class, $service);
        self::assertInstanceOf(PlaceNormalizer::class, $normalizer);
        self::assertInstanceOf(FamilyDiscoveryProfile::class, $profile);

        $old = new ProviderSourceRecord('', 'Old dataset', null, 'old-record', provider: 'Old provider', resource: 'places', version: '1');
        $this->connection->update('place_candidates', ['source_provenance' => json_encode([$old], \JSON_THROW_ON_ERROR)], ['id' => self::CANDIDATE]);
        $replacement = new ProviderSourceRecord('', 'New dataset', null, 'new-record', provider: 'New provider', resource: 'places', version: '2');
        $source = new ProviderPlace('gers-normal', '2026-11-12.0', '4', 'Refreshed provider name', 50.0413, 21.999, 'Provider address', '35-001', 'Rzeszów', 'PL', null, null, ['playground'], 'playground', 0.96, OvertureOperatingStatus::OPEN->value, ['id' => 'gers-normal', 'name' => 'Refreshed provider name'], [$replacement]);
        $service->import(self::RUN, $source, $normalizer->normalize($source), $profile->classify($source, $normalizer->normalize($source)));
        try {
            $service->resolveUnlicensedProvenance(self::CANDIDATE, 1, SourceProvenanceFingerprint::fromArray($old->jsonSerialize()), 'Reviewed-1.0', 'admin@example.test');
            self::fail('A selector from pre-refresh provenance must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('stale', $exception->getMessage());
        }

        $duplicate = $replacement->jsonSerialize();
        $this->connection->update('place_candidates', ['source_provenance' => json_encode([$duplicate, $duplicate], \JSON_THROW_ON_ERROR)], ['id' => self::CANDIDATE]);
        try {
            $service->resolveUnlicensedProvenance(self::CANDIDATE, 1, SourceProvenanceFingerprint::fromArray($duplicate), 'Reviewed-1.0', 'admin@example.test');
            self::fail('An ambiguous source identity must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('ambiguous', $exception->getMessage());
        }

        $licensed = $replacement->jsonSerialize();
        $licensed['license'] = 'Provider-License-1.0';
        $this->connection->update('place_candidates', ['source_provenance' => json_encode([$licensed], \JSON_THROW_ON_ERROR)], ['id' => self::CANDIDATE]);
        try {
            $service->resolveUnlicensedProvenance(self::CANDIDATE, 1, SourceProvenanceFingerprint::fromArray($licensed), 'Replacement-License-1.0', 'admin@example.test');
            self::fail('A provider-supplied license must not be overwritten.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('cannot be overwritten', $exception->getMessage());
        }
        self::assertSame('Provider-License-1.0', $this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
    }

    private function place(string $release, ?string $status, string $name): ProviderPlace
    {
        return new ProviderPlace('gers-normal', $release, '2', $name, 50.0413, 21.999, 'New source address', '35-001', 'Rzeszów', 'PL', 'https://source.example', null, ['playground'], 'playground', 0.95, $status, ['id' => 'gers-normal', 'name' => $name, 'operating_status' => $status], [new ProviderSourceRecord('/names/primary', 'Foursquare', 'Apache-2.0', 'fsq-1', '2026-08-01T00:00:00Z')]);
    }
}
