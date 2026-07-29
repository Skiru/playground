<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\Attributes\BeforeClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EffectiveProvenanceMigrationTest extends KernelTestCase
{
    private Connection $connection;

    #[BeforeClass]
    public static function loadMigration(): void
    {
        require_once \dirname(__DIR__, 3).'/migrations/Version20260729100000.php';
    }

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

    public function testUpRaisesOnlyTheEffectiveSourceLinkCapacity(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        $sql = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());

        self::assertSame([
            'ALTER TABLE place_source_links DROP CONSTRAINT chk_source_link_provenance_size',
            'ALTER TABLE place_source_links ADD CONSTRAINT chk_source_link_provenance_size CHECK (octet_length(source_provenance::text) <= 32768)',
        ], $sql);
    }

    public function testSafeEmptyDataDownPlansThePredecessorConstraint(): void
    {
        $migration = $this->migration();
        $migration->down(new Schema());
        $sql = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());

        self::assertSame([
            'ALTER TABLE place_source_links DROP CONSTRAINT chk_source_link_provenance_size',
            'ALTER TABLE place_source_links ADD CONSTRAINT chk_source_link_provenance_size CHECK (octet_length(source_provenance::text) <= 16384)',
        ], $sql);
    }

    public function testDownAbortsWhenR5AuditEvidenceExists(): void
    {
        $this->connection->executeStatement("INSERT INTO place_candidate_audit_events (id, candidate_id, actor_type, action, changed_fields, created_at, details) VALUES (gen_random_uuid(), '00000000-0000-7000-8000-000000000902', 'SYSTEM', 'ROLLBACK_GUARD_TEST', '[]'::jsonb, now(), '{\"evidence\":true}'::jsonb)");

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('audit compliance evidence');
        $this->migration()->down(new Schema());
    }

    public function testDownAbortsWhenResolutionOverlayExceedsPredecessorCapacity(): void
    {
        $this->connection->executeStatement('UPDATE place_candidates SET source_license_resolutions = ?::jsonb WHERE id = ?', [$this->jsonOfBytes(16_385), '00000000-0000-7000-8000-000000000902']);

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('reviewed source-license resolutions');
        $this->migration()->down(new Schema());
    }

    public function testDownAbortsWhenEffectiveSourceLinkExceedsPredecessorCapacity(): void
    {
        $this->connection->executeStatement("INSERT INTO place_source_links (id, place_id, source, external_id, source_release, first_linked_at, last_seen_at, last_payload_hash, source_provenance) VALUES (gen_random_uuid(), '00000000-0000-7000-8000-000000000400', 'overture', 'r6-rollback-effective-link', '2099-01-01.0', now(), now(), repeat('a', 64), ?::jsonb)", [$this->jsonOfBytes(16_385)]);

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('effective source-link provenance');
        $this->migration()->down(new Schema());
    }

    private function migration(): \DoctrineMigrations\Version20260729100000
    {
        return new \DoctrineMigrations\Version20260729100000($this->connection, new NullLogger());
    }

    private function jsonOfBytes(int $bytes): string
    {
        $base = (int) $this->connection->fetchOne("SELECT octet_length('{\"padding\":\"\"}'::jsonb::text)");
        $json = json_encode(['padding' => str_repeat('x', $bytes - $base)], \JSON_THROW_ON_ERROR);
        self::assertSame($bytes, (int) $this->connection->fetchOne('SELECT octet_length(?::jsonb::text)', [$json]));

        return $json;
    }
}
