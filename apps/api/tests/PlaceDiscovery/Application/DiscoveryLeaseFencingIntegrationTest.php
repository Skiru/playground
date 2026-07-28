<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\DiscoveryOperationLock;
use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use App\PlaceDiscovery\Application\LostDiscoveryRunLease;
use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class DiscoveryLeaseFencingIntegrationTest extends KernelTestCase
{
    private const AREA = '00000000-0000-7000-8000-000000000989';
    private const RUN = '00000000-0000-7000-8000-000000000988';

    private Connection $first;
    private Connection $second;

    protected function setUp(): void
    {
        self::bootKernel();
        $first = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $first);
        $this->first = $first;
        $this->second = DriverManager::getConnection($first->getParams());
        $this->cleanup();
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->first->insert('place_discovery_areas', ['id' => self::AREA, 'name' => 'fencing', 'enabled' => 'true', 'country_code' => 'PL', 'center_latitude' => 50, 'center_longitude' => 20, 'radius_km' => 1, 'bbox_west' => 19.9, 'bbox_south' => 49.9, 'bbox_east' => 20.1, 'bbox_north' => 50.1, 'minimum_confidence' => 0.5, 'maximum_candidates_per_run' => 1, 'discovery_profile' => 'family-v1', 'created_at' => $now, 'updated_at' => $now]);
        $this->first->insert('place_discovery_runs', ['id' => self::RUN, 'source' => 'overture', 'source_release' => '2099-11-01.0', 'area_id' => self::AREA, 'status' => 'QUEUED', 'attempt' => 1, 'dispatch_state' => 'DISPATCHED', 'created_at' => $now]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->second->close();
        self::ensureKernelShutdown();
    }

    public function testStaleWorkerCannotHeartbeatCompleteFailOrAdvanceAreaAfterNewClaim(): void
    {
        $runsA = $this->runs($this->first);
        $runsB = $this->runs($this->second);
        $claim = new \ReflectionMethod(DiscoveryRunOrchestrator::class, 'claim');
        $heartbeat = new \ReflectionMethod(DiscoveryRunOrchestrator::class, 'heartbeat');
        $complete = new \ReflectionMethod(DiscoveryRunOrchestrator::class, 'complete');
        $fail = new \ReflectionMethod(DiscoveryRunOrchestrator::class, 'fail');
        $runA = $claim->invoke($runsA, self::RUN, 'worker-a');
        self::assertIsArray($runA);
        self::assertSame(1, $runA['execution_token']);
        $this->second->executeStatement("UPDATE place_discovery_runs SET lease_expires_at = now() - interval '1 second' WHERE id = ?", [self::RUN]);
        $runB = $claim->invoke($runsB, self::RUN, 'worker-b');
        self::assertIsArray($runB);
        self::assertSame(2, $runB['execution_token']);

        foreach ([
            static fn () => $heartbeat->invoke($runsA, self::RUN, 'worker-a', 1),
            static fn () => $complete->invoke($runsA, self::RUN, 'worker-a', 1, $runA, self::counts(), [], self::now(), microtime(true)),
            static fn () => $fail->invoke($runsA, self::RUN, 'worker-a', 1, 'stale failure', self::now(), microtime(true)),
        ] as $staleWrite) {
            try {
                $staleWrite();
                self::fail('Every stale write must be fenced.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                self::assertInstanceOf(LostDiscoveryRunLease::class, $exception);
            }
        }
        self::assertNull($this->first->fetchOne('SELECT last_successful_release FROM place_discovery_areas WHERE id = ?', [self::AREA]));
        self::assertSame('worker-b', $this->first->fetchOne('SELECT worker_id FROM place_discovery_runs WHERE id = ?', [self::RUN]));

        $complete->invoke($runsB, self::RUN, 'worker-b', 2, $runB, self::counts(), [], self::now(), microtime(true));
        self::assertSame('COMPLETED', $this->first->fetchOne('SELECT status FROM place_discovery_runs WHERE id = ?', [self::RUN]));
        self::assertSame('2099-11-01.0', $this->first->fetchOne('SELECT last_successful_release FROM place_discovery_areas WHERE id = ?', [self::AREA]));
    }

    /** @return array<string, int> */
    private static function counts(): array
    {
        return ['discovered' => 0, 'inserted' => 0, 'refreshed' => 0, 'linked' => 0, 'duplicates' => 0, 'skipped' => 0, 'malformed' => 0, 'failed' => 0];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    private function runs(Connection $connection): DiscoveryRunOrchestrator
    {
        return new DiscoveryRunOrchestrator($connection, new FencingProvider(), self::getContainer()->get(PlaceNormalizer::class), self::getContainer()->get(FamilyDiscoveryProfile::class), self::getContainer()->get(PlaceDiscoveryService::class), self::getContainer()->get(DiscoveryOperationLock::class), new FencingBus(), new NullLogger(), true);
    }

    private function cleanup(): void
    {
        $this->first->executeStatement('DELETE FROM place_discovery_runs WHERE id = ?', [self::RUN]);
        $this->first->executeStatement('DELETE FROM place_discovery_areas WHERE id = ?', [self::AREA]);
    }
}

final class FencingProvider implements PlaceDiscoveryProvider
{
    public function getProviderName(): string
    {
        return 'overture';
    }

    public function getLatestRelease(): string
    {
        return '2099-11-01.0';
    }

    public function assertReleaseAvailable(string $release): void
    {
    }

    public function streamPlaces(DiscoveryArea $area, string $profile, string $release, int $limit): iterable
    {
        return [];
    }
}

final class FencingBus implements MessageBusInterface
{
    /** @param array<StampInterface> $stamps */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return Envelope::wrap($message, $stamps);
    }
}
