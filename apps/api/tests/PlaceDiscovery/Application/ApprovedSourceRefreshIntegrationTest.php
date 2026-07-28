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

    private function place(string $release, string $status, string $name): ProviderPlace
    {
        return new ProviderPlace('gers-normal', $release, '2', $name, 50.0413, 21.999, 'New source address', '35-001', 'Rzeszów', 'PL', 'https://source.example', null, ['playground'], 'playground', 0.95, $status, ['id' => 'gers-normal', 'name' => $name, 'operating_status' => $status], [new ProviderSourceRecord('names.primary', 'Foursquare', 'Apache-2.0', 'fsq-1', '2026-08-01T00:00:00Z')]);
    }
}
