<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Infrastructure;

use App\PlaceDiscovery\Domain\OvertureOperatingStatus;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\PlaceDiscovery\Infrastructure\Doctrine\DbalDuplicatePlaceLookup;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DuplicateLookupIntegrationTest extends KernelTestCase
{
    private Connection $connection;
    private DbalDuplicatePlaceLookup $lookup;
    private PlaceNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $lookup = self::getContainer()->get(DbalDuplicatePlaceLookup::class);
        $normalizer = self::getContainer()->get(PlaceNormalizer::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(DbalDuplicatePlaceLookup::class, $lookup);
        self::assertInstanceOf(PlaceNormalizer::class, $normalizer);
        $this->connection = $connection;
        $this->lookup = $lookup;
        $this->normalizer = $normalizer;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::ensureKernelShutdown();
    }

    public function testWebsiteHostSubstringDoesNotProduceAnExactHostDuplicate(): void
    {
        $this->connection->executeStatement("UPDATE places SET name='Different place', normalized_name='different place', website_url='https://notexample.com/path', latitude=50, longitude=20 WHERE id='00000000-0000-7000-8000-000000000400'");
        $source = $this->place('source-host', 'Unrelated name', 50, 20, 'https://example.com');
        $assessment = $this->lookup->assess($source, $this->normalizer->normalize($source));

        self::assertLessThan(70, $assessment->score);
        self::assertNotContains('website_host', $assessment->reasons);
    }

    public function testSameNameDistantCandidateIsNotAProbableDuplicate(): void
    {
        $this->insertCandidate('distant', 'Same Family Park', 0, 0);
        $source = $this->place('source-distant', 'Same Family Park', 50, 20);
        $assessment = $this->lookup->assess($source, $this->normalizer->normalize($source));

        self::assertSame(45, $assessment->score);
        self::assertContains('exact_normalized_name', $assessment->reasons);
    }

    public function testCandidateToCandidateDuplicateAndClosestBeyondOldUnorderedCutoffAreRetained(): void
    {
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO place_candidates (id, source, external_id, source_release, source_payload_hash, source_snapshot, name, normalized_name, latitude, longitude, source_categories, discovery_score, discovery_reasons, status, first_seen_at, last_seen_at, created_at, updated_at)
SELECT gen_random_uuid(), 'overture', 'bulk-'||n, '2099-05-01.0', repeat('a',64), '{"id":"bulk"}'::jsonb, 'Bulk Family Park', 'bulk family park', 50 + ((151-n)*0.00001), 20, '[]'::jsonb, 50, '[]'::jsonb, 'PENDING', now(), now(), now(), now()
FROM generate_series(1,150) AS n
SQL);
        $closest = (string) $this->connection->fetchOne("SELECT id FROM place_candidates WHERE external_id='bulk-150'");
        $source = $this->place('source-bulk', 'Bulk Family Park', 50.00001, 20);
        $assessment = $this->lookup->assess($source, $this->normalizer->normalize($source));

        self::assertGreaterThanOrEqual(70, $assessment->score);
        self::assertContains($closest, $assessment->candidateIds);
        self::assertLessThanOrEqual(10, \count($assessment->candidateIds) + \count($assessment->placeIds));
    }

    private function insertCandidate(string $externalId, string $name, float $latitude, float $longitude): void
    {
        $this->connection->executeStatement("INSERT INTO place_candidates (id, source, external_id, source_release, source_payload_hash, source_snapshot, name, normalized_name, latitude, longitude, source_categories, discovery_score, discovery_reasons, status, first_seen_at, last_seen_at, created_at, updated_at) VALUES (gen_random_uuid(),'overture',?,'2099-05-01.0',repeat('a',64),'{\"id\":\"test\"}'::jsonb,?,?,?,?,'[]'::jsonb,50,'[]'::jsonb,'PENDING',now(),now(),now(),now())", [$externalId, $name, $this->normalizer->comparison($name), $latitude, $longitude]);
    }

    private function place(string $id, string $name, float $latitude, float $longitude, ?string $website = null): ProviderPlace
    {
        return new ProviderPlace($id, '2099-05-01.0', '1', $name, $latitude, $longitude, null, null, null, 'PL', $website, null, ['playground'], 'playground', 0.9, OvertureOperatingStatus::OPEN->value, ['id' => $id]);
    }
}
