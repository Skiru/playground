<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\PlaceDiscovery\Domain\ProviderSourceRecord;
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

        $closed = $this->place('2026-08-20.0', 'closed_permanently', 'Changed provider name');
        $classification = $profile->classify($closed, $normalizer->normalize($closed));
        self::assertSame('linked', $service->import(self::RUN, $closed, $normalizer->normalize($closed), $classification));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT source_closed_review_required FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT source_changed_after_edit FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertSame('Apache-2.0', $this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_source_links WHERE external_id = 'gers-normal'"));
        self::assertSame($publicBefore, $this->connection->fetchAssociative('SELECT name,status,indoor,outdoor,free_entry,version FROM places WHERE id = ?', [$placeId]));

        $service->import(self::RUN, $closed, $normalizer->normalize($closed), $classification);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'CLOSURE_REVIEW_FLAGGED'", [self::CANDIDATE]));

        $open = $this->place('2026-09-17.0', 'open', 'Open provider name');
        $service->import(self::RUN, $open, $normalizer->normalize($open), $profile->classify($open, $normalizer->normalize($open)));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT source_closed_review_required FROM place_candidates WHERE id = ?', [self::CANDIDATE]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_REOPENED'", [self::CANDIDATE]));
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
        $source = new ProviderPlace('gers-normal', '2026-10-15.0', '3', 'New provider name', 50.2, 21.8, 'Provider address', '00-001', 'Provider locality', 'PL', null, null, ['playground'], 'playground', 0.96, 'closed_permanently', ['id' => 'gers-normal', 'name' => 'New provider name', 'operating_status' => 'closed_permanently'], [new ProviderSourceRecord('', 'Overture', null, provider: 'Overture Maps Foundation', resource: 'places', version: '3')]);
        self::assertSame('refreshed', $service->import(self::RUN, $source, $normalizer->normalize($source), $profile->classify($source, $normalizer->normalize($source))));
        $candidate = $this->connection->fetchAssociative('SELECT * FROM place_candidates WHERE id = ?', [self::CANDIDATE]);
        self::assertIsArray($candidate);
        self::assertSame(['Manual family name', 'Manual 7', 'Manual locality'], [$candidate['name'], $candidate['address_line1'], $candidate['locality']]);
        self::assertSame('2026-10-15.0', $candidate['source_release']);
        self::assertSame('3', $candidate['source_record_version']);
        self::assertSame('closed_permanently', $candidate['operating_status']);
        self::assertNull($this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_candidates WHERE id = ?", [self::CANDIDATE]));
        try {
            $service->approve(self::CANDIDATE, 2, 'admin@example.test');
            self::fail('Unresolved current-release licensing must block approval.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('licensing', $exception->getMessage());
        }
        try {
            $service->resolveUnlicensedProvenance(self::CANDIDATE, 2, '<script>', 'admin@example.test');
            self::fail('Malformed reviewed license identifiers must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('bounded', $exception->getMessage());
        }
        $service->resolveUnlicensedProvenance(self::CANDIDATE, 2, 'Reviewed-License-1.0', 'admin@example.test');
        $placeId = $service->approve(self::CANDIDATE, 3, 'admin@example.test');
        self::assertSame('2026-10-15.0', $this->connection->fetchOne('SELECT source_release FROM place_source_links WHERE place_id = ?', [$placeId]));
        self::assertSame('Reviewed-License-1.0', $this->connection->fetchOne("SELECT source_provenance->0->>'license' FROM place_source_links WHERE place_id = ?", [$placeId]));
        self::assertSame('SOURCE_LICENSE_RESOLVED', $this->connection->fetchOne("SELECT action FROM place_candidate_audit_events WHERE candidate_id = ? AND action = 'SOURCE_LICENSE_RESOLVED'", [self::CANDIDATE]));
    }

    private function place(string $release, string $status, string $name): ProviderPlace
    {
        return new ProviderPlace('gers-normal', $release, '2', $name, 50.0413, 21.999, 'New source address', '35-001', 'Rzeszów', 'PL', 'https://source.example', null, ['playground'], 'playground', 0.95, $status, ['id' => 'gers-normal', 'name' => $name, 'operating_status' => $status], [new ProviderSourceRecord('/names/primary', 'Foursquare', 'Apache-2.0', 'fsq-1', '2026-08-01T00:00:00Z')]);
    }
}
