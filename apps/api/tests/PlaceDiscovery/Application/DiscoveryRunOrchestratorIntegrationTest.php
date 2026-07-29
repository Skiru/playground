<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\DiscoveryLockKey;
use App\PlaceDiscovery\Application\DiscoveryOperationLock;
use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use App\PlaceDiscovery\Application\Message\DiscoverPlacesForArea;
use App\PlaceDiscovery\Application\Message\DiscoverPlacesForAreaHandler;
use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\OvertureOperatingStatus;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use App\PlaceDiscovery\Domain\ProviderSourceRecord;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class DiscoveryRunOrchestratorIntegrationTest extends KernelTestCase
{
    private const AREA = '00000000-0000-7000-8000-000000000900';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->cleanup();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::ensureKernelShutdown();
    }

    public function testExceptionAfterCreationAndAutomaticRedeliveryCompleteExactlyOneRun(): void
    {
        $provider = new DeterministicProvider(true, 'redelivery');
        $runs = $this->runs($provider);
        $runId = $runs->enqueue(self::AREA, $provider->release, 'test');
        self::assertNotNull($runId);
        try {
            $runs->execute($runId);
            self::fail('First delivery must fail.');
        } catch (\RuntimeException) {
            self::assertSame('FAILED', $this->connection->fetchOne('SELECT status FROM place_discovery_runs WHERE id = ?', [$runId]));
        }

        $runs->execute($runId);
        $runs->execute($runId);

        self::assertSame('COMPLETED', $this->connection->fetchOne('SELECT status FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidates WHERE external_id = 'redelivery'"));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT transport_delivery_count FROM place_discovery_runs WHERE id = ?', [$runId]));
    }

    public function testExplicitRetryCreatesNextAttemptWhileDuplicateSchedulerDispatchDoesNot(): void
    {
        $provider = new DeterministicProvider(false, 'retry');
        $runs = $this->runs($provider);
        $first = $runs->enqueue(self::AREA, $provider->release, 'scheduler');
        self::assertNotNull($first);
        self::assertNull($runs->enqueue(self::AREA, $provider->release, 'scheduler'));
        $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'FAILED' WHERE id = ?", [$first]);

        $second = $runs->retry($first, 'admin@example.test');

        self::assertNotSame($first, $second);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT attempt FROM place_discovery_runs WHERE id = ?', [$second]));
        self::assertSame($first, $this->connection->fetchOne('SELECT retry_of_run_id FROM place_discovery_runs WHERE id = ?', [$second]));
        self::assertSame('HUMAN_RETRY', $this->connection->fetchOne('SELECT trigger_type FROM place_discovery_runs WHERE id = ?', [$second]));
    }

    public function testTransportFailurePersistsRecoverableOutboxAndRepeatedReconciliationIsIdempotent(): void
    {
        $provider = new DeterministicProvider(false, 'outbox');
        $bus = new RecordingBus(failures: 1);
        $runs = $this->runs($provider, $bus);
        try {
            $runs->enqueueAndDispatch(self::AREA, $provider->release, 'admin');
            self::fail('Forced transport failure must be observable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced transport failure', $exception->getMessage());
        }
        $runId = (string) $this->connection->fetchOne("SELECT id FROM place_discovery_runs WHERE source_release = ? AND dispatch_state = 'PENDING'", [$provider->release]);
        self::assertNotSame('', $runId);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT dispatch_attempts FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertSame(1, $runs->reconcilePendingDispatches());
        self::assertSame(0, $runs->reconcilePendingDispatches());
        self::assertSame('DISPATCHED', $this->connection->fetchOne('SELECT dispatch_state FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertSame(1, $bus->successfulDeliveries);

        $runs->execute($runId);
        $runs->execute($runId);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM place_candidates WHERE external_id = 'outbox'"));
    }

    public function testCrashBeforeDeliveryLeavesPendingRunThatReconcilesOnce(): void
    {
        $provider = new DeterministicProvider(false, 'crash');
        $bus = new RecordingBus();
        $runs = $this->runs($provider, $bus);
        $runId = $runs->enqueue(self::AREA, $provider->release, 'scheduler');
        self::assertNotNull($runId);
        self::assertSame('PENDING', $this->connection->fetchOne('SELECT dispatch_state FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertSame(1, $runs->reconcilePendingDispatches());
        self::assertSame(0, $runs->reconcilePendingDispatches());
        self::assertSame(1, $bus->successfulDeliveries);
    }

    public function testDisabledQueuedDeliveryIsCancelledAndCanBeExplicitlyRetriedAfterEnable(): void
    {
        $provider = new DeterministicProvider(false, 'disabled');
        $enabledRuns = $this->runs($provider);
        $runId = $enabledRuns->enqueue(self::AREA, $provider->release, 'scheduler');
        self::assertNotNull($runId);
        (new DiscoverPlacesForAreaHandler($enabledRuns, false))(new DiscoverPlacesForArea($runId));
        self::assertSame('CANCELLED', $this->connection->fetchOne('SELECT status FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertStringContainsString('disabled', (string) $this->connection->fetchOne('SELECT error_summary FROM place_discovery_runs WHERE id = ?', [$runId]));
        $retry = $enabledRuns->retry($runId, 'admin');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT attempt FROM place_discovery_runs WHERE id = ?', [$retry]));
    }

    public function testRetryExhaustionRemainsFailedAndStaleLeaseCanRecoverSameRun(): void
    {
        $provider = new DeterministicProvider(true, 'stale', alwaysFail: true);
        $runs = $this->runs($provider);
        $runId = $runs->enqueue(self::AREA, $provider->release, 'test');
        self::assertNotNull($runId);
        for ($delivery = 0; $delivery < 5; ++$delivery) {
            try {
                $runs->execute($runId);
            } catch (\RuntimeException) {
            }
        }
        self::assertSame('FAILED', $this->connection->fetchOne('SELECT status FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT transport_delivery_count FROM place_discovery_runs WHERE id = ?', [$runId]));

        $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'RUNNING', worker_id = 'dead-worker', last_heartbeat_at = now() - interval '10 minutes', lease_expires_at = now() - interval '5 minutes' WHERE id = ?", [$runId]);
        $runs->recoverStale($runId, 'admin@example.test');
        self::assertSame('QUEUED', $this->connection->fetchOne('SELECT status FROM place_discovery_runs WHERE id = ?', [$runId]));
        self::assertNull($this->connection->fetchOne('SELECT worker_id FROM place_discovery_runs WHERE id = ?', [$runId]));
    }

    public function testCanonicalLockUsesTheSameIdentityForEquivalentCliAndWorkerOperations(): void
    {
        $provider = new DeterministicProvider(false, 'lock');
        $keys = self::getContainer()->get(DiscoveryLockKey::class);
        self::assertInstanceOf(DiscoveryLockKey::class, $keys);
        self::assertSame($keys->operation('overture', $provider->release, self::AREA), $keys->operation($provider->getProviderName(), $provider->release, self::AREA));
    }

    private function runs(PlaceDiscoveryProvider $provider, ?RecordingBus $bus = null, bool $enabled = true): DiscoveryRunOrchestrator
    {
        $normalizer = self::getContainer()->get(PlaceNormalizer::class);
        $profile = self::getContainer()->get(FamilyDiscoveryProfile::class);
        $service = self::getContainer()->get(PlaceDiscoveryService::class);
        $locks = self::getContainer()->get(DiscoveryOperationLock::class);
        self::assertInstanceOf(PlaceNormalizer::class, $normalizer);
        self::assertInstanceOf(FamilyDiscoveryProfile::class, $profile);
        self::assertInstanceOf(PlaceDiscoveryService::class, $service);
        self::assertInstanceOf(DiscoveryOperationLock::class, $locks);
        $bus ??= new RecordingBus();

        return new DiscoveryRunOrchestrator($this->connection, $provider, $normalizer, $profile, $service, $locks, $bus, new NullLogger(), $enabled);
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement("DELETE FROM place_candidate_audit_events WHERE candidate_id IN (SELECT id FROM place_candidates WHERE external_id IN ('redelivery','retry','stale','lock','outbox','crash','disabled'))");
        $this->connection->executeStatement("DELETE FROM place_candidates WHERE external_id IN ('redelivery','retry','stale','lock','outbox','crash','disabled')");
        $this->connection->executeStatement("DELETE FROM place_discovery_runs WHERE source_release LIKE '2099-01-0%.0'");
    }
}

final class RecordingBus implements MessageBusInterface
{
    public int $successfulDeliveries = 0;

    public function __construct(private int $failures = 0)
    {
    }

    /** @param array<StampInterface> $stamps */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->failures-- > 0) {
            throw new \RuntimeException('forced transport failure');
        }
        ++$this->successfulDeliveries;

        return Envelope::wrap($message, $stamps);
    }
}

final class DeterministicProvider implements PlaceDiscoveryProvider
{
    public string $release;

    private int $deliveries = 0;

    public function __construct(private readonly bool $failFirst, private readonly string $externalId, private readonly bool $alwaysFail = false)
    {
        $this->release = '2099-01-0'.match ($externalId) {
            'redelivery' => '1', 'retry' => '2', 'stale' => '3', 'outbox' => '5', 'crash' => '6', 'disabled' => '7', default => '4',
        }.'.0';
    }

    public function getProviderName(): string
    {
        return 'overture';
    }

    public function getLatestRelease(): string
    {
        return $this->release;
    }

    public function assertReleaseAvailable(string $release): void
    {
        if ($release !== $this->release) {
            throw new \RuntimeException('release unavailable');
        }
    }

    public function streamPlaces(DiscoveryArea $area, string $profile, string $release, int $limit): iterable
    {
        if ($this->alwaysFail || ($this->failFirst && 0 === $this->deliveries++)) {
            throw new \RuntimeException('deterministic provider failure');
        }
        yield new ProviderPlace($this->externalId, $release, '1', 'Family playground '.$this->externalId, 50.0413, 21.999, 'Rynek 1', '35-001', 'Rzeszów', 'PL', null, null, ['playground'], 'playground', 0.95, OvertureOperatingStatus::OPEN->value, ['id' => $this->externalId], [new ProviderSourceRecord('', 'Overture', 'CDLA-Permissive-2.0')]);
    }
}
