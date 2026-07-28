<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application;

use App\PlaceDiscovery\Application\Message\DiscoverPlacesForArea;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DiscoveryRunOrchestrator
{
    private const LEASE_SECONDS = 120;
    private const RAW_FETCH_MULTIPLIER = 25;

    public function __construct(
        private Connection $connection,
        private PlaceDiscoveryProvider $provider,
        private PlaceNormalizer $normalizer,
        private FamilyDiscoveryProfile $profile,
        private PlaceDiscoveryService $service,
        private DiscoveryOperationLock $locks,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function enqueue(string $areaId, string $release, string $requestedBy, bool $explicitRetry = false, ?string $retryOf = null): ?string
    {
        $this->provider->assertReleaseAvailable($release);
        $source = $this->provider->getProviderName();
        $lock = $this->locks->operation($source, $release, $areaId);
        if (!$lock->acquire()) {
            return null;
        }
        try {
            return $this->connection->transactional(function () use ($source, $release, $areaId, $requestedBy, $explicitRetry, $retryOf): ?string {
                if (!$explicitRetry && false !== $this->connection->fetchOne('SELECT 1 FROM place_discovery_runs WHERE source = ? AND source_release = ? AND area_id = ?', [$source, $release, $areaId])) {
                    return null;
                }
                $attempt = 1 + (int) $this->connection->fetchOne('SELECT COALESCE(MAX(attempt), 0) FROM place_discovery_runs WHERE source = ? AND source_release = ? AND area_id = ?', [$source, $release, $areaId]);
                $id = Uuid::v7()->toRfc4122();
                $now = $this->now();
                $this->connection->insert('place_discovery_runs', ['id' => $id, 'source' => $source, 'source_release' => $release, 'area_id' => $areaId, 'attempt' => $attempt, 'status' => 'QUEUED', 'requested_by' => mb_substr($requestedBy, 0, 160), 'retry_of_run_id' => $retryOf, 'trigger_type' => $explicitRetry ? 'HUMAN_RETRY' : 'DISPATCH', 'created_at' => $now]);

                return $id;
            });
        } finally {
            $lock->release();
        }
    }

    public function enqueueAndDispatch(string $areaId, string $release, string $requestedBy, bool $explicitRetry = false, ?string $retryOf = null): ?string
    {
        $id = $this->enqueue($areaId, $release, $requestedBy, $explicitRetry, $retryOf);
        if (null !== $id) {
            $this->bus->dispatch(new DiscoverPlacesForArea($id));
        }

        return $id;
    }

    public function dispatch(string $runId): void
    {
        if (false === $this->connection->fetchOne("SELECT 1 FROM place_discovery_runs WHERE id = ? AND status = 'QUEUED'", [$runId])) {
            throw new \DomainException('Only a queued discovery run may be dispatched.');
        }
        $this->bus->dispatch(new DiscoverPlacesForArea($runId));
    }

    public function retry(string $failedRunId, string $requestedBy): string
    {
        $run = $this->connection->fetchAssociative("SELECT source_release, area_id FROM place_discovery_runs WHERE id = ? AND status IN ('FAILED','PARTIAL')", [$failedRunId]);
        if (false === $run) {
            throw new \DomainException('Only failed or partial runs may be retried.');
        }
        $id = $this->enqueueAndDispatch((string) $run['area_id'], (string) $run['source_release'], $requestedBy, true, $failedRunId);
        if (null === $id) {
            throw new \DomainException('An equivalent discovery operation is active.');
        }

        return $id;
    }

    public function recoverStale(string $runId, string $requestedBy): void
    {
        $changed = $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'FAILED', completed_at = ?, error_summary = ?, error_samples = jsonb_build_array(?::text), worker_id = NULL, lease_expires_at = NULL WHERE id = ? AND status = 'RUNNING' AND lease_expires_at < ?", [$this->now(), 'Stale worker lease recovered by '.mb_substr($requestedBy, 0, 160), 'stale_worker_lease', $runId, $this->now()]);
        if (1 !== $changed) {
            throw new \DomainException('Only a run with an expired active lease may be recovered.');
        }
        $this->dispatchFailedRedelivery($runId);
    }

    private function dispatchFailedRedelivery(string $runId): void
    {
        if (false === $this->connection->fetchOne("SELECT 1 FROM place_discovery_runs WHERE id = ? AND status = 'FAILED'", [$runId])) {
            throw new \DomainException('Only a failed run may be redelivered.');
        }
        $this->bus->dispatch(new DiscoverPlacesForArea($runId));
    }

    public function execute(string $runId, ?int $limitOverride = null): void
    {
        $identity = $this->connection->fetchAssociative('SELECT source, source_release, area_id FROM place_discovery_runs WHERE id = ?', [$runId]);
        if (false === $identity) {
            throw new \DomainException('Discovery run was not found.');
        }
        $lock = $this->locks->operation((string) $identity['source'], (string) $identity['source_release'], (string) $identity['area_id']);
        $lock->acquire(true);
        $worker = mb_substr((gethostname() ?: 'worker').':'.getmypid().':'.Uuid::v7()->toRfc4122(), 0, 160);
        $started = microtime(true);
        try {
            $run = $this->claim($runId, $worker);
            if (null === $run) {
                return;
            }
            $areaRow = $this->connection->fetchAssociative('SELECT * FROM place_discovery_areas WHERE id = ?', [$run['area_id']]);
            if (false === $areaRow) {
                throw new \DomainException('Discovery area was not found.');
            }
            $area = new DiscoveryArea((string) $areaRow['id'], (string) $areaRow['name'], (bool) $areaRow['enabled'], (string) $areaRow['country_code'], (float) $areaRow['center_latitude'], (float) $areaRow['center_longitude'], (float) $areaRow['radius_km'], (float) $areaRow['minimum_confidence'], (int) $areaRow['maximum_candidates_per_run'], (string) $areaRow['discovery_profile'], (int) $areaRow['version']);
            $maximum = null === $limitOverride ? $area->maximumCandidatesPerRun : min($area->maximumCandidatesPerRun, max(1, $limitOverride));
            $counts = ['discovered' => 0, 'inserted' => 0, 'refreshed' => 0, 'linked' => 0, 'duplicates' => 0, 'skipped' => 0, 'malformed' => 0, 'failed' => 0];
            $skipped = [];
            foreach ($this->provider->streamPlaces($area, $area->profile, (string) $run['source_release'], min(1000, $maximum * self::RAW_FETCH_MULTIPLIER)) as $place) {
                ++$counts['discovered'];
                if (null !== $place->confidence && $place->confidence < $area->minimumConfidence) {
                    ++$counts['skipped'];
                    $skipped['confidence'] = ($skipped['confidence'] ?? 0) + 1;
                    continue;
                }
                $normalized = $this->normalizer->normalize($place);
                $classification = $this->profile->classify($place, $normalized);
                if (!$classification->discoverable) {
                    ++$counts['skipped'];
                    $skipped['profile'] = ($skipped['profile'] ?? 0) + 1;
                    continue;
                }
                $result = $this->service->import($runId, $place, $normalized, $classification);
                ++$counts[$result];
                if (false !== $this->connection->fetchOne("SELECT 1 FROM place_candidates WHERE source = ? AND external_id = ? AND status = 'POSSIBLE_DUPLICATE'", [$this->provider->getProviderName(), $place->externalId])) {
                    ++$counts['duplicates'];
                }
                if (0 === $counts['discovered'] % 20) {
                    $this->heartbeat($runId, $worker);
                }
                if ($counts['inserted'] + $counts['refreshed'] + $counts['linked'] >= $maximum) {
                    break;
                }
            }
            $now = $this->now();
            $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'COMPLETED', completed_at = ?, duration_ms = ?, discovered_count = ?, inserted_count = ?, updated_count = ?, linked_count = ?, duplicate_count = ?, skipped_count = ?, malformed_count = ?, failed_count = ?, skipped_reasons = ?::jsonb, worker_id = NULL, lease_expires_at = NULL, last_heartbeat_at = ? WHERE id = ? AND worker_id = ?", [$now, (int) ((microtime(true) - $started) * 1000), $counts['discovered'], $counts['inserted'], $counts['refreshed'], $counts['linked'], $counts['duplicates'], $counts['skipped'], $counts['malformed'], $counts['failed'], json_encode($skipped, \JSON_THROW_ON_ERROR), $now, $runId, $worker]);
            $this->connection->executeStatement('UPDATE place_discovery_areas SET last_successful_release = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$run['source_release'], $now, $run['area_id']]);
            $this->logger->info('Place discovery run completed.', ['discovery_run_id' => $runId, 'counters' => $counts]);
        } catch (\Throwable $exception) {
            $now = $this->now();
            $diagnostic = mb_substr($exception->getMessage(), 0, 1000);
            $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'FAILED', completed_at = ?, duration_ms = ?, failed_count = failed_count + 1, error_summary = ?, error_samples = jsonb_build_array(?::text), worker_id = NULL, lease_expires_at = NULL, last_heartbeat_at = ? WHERE id = ?", [$now, (int) ((microtime(true) - $started) * 1000), $diagnostic, $diagnostic, $now, $runId]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed>|null */
    private function claim(string $runId, string $worker): ?array
    {
        return $this->connection->transactional(function () use ($runId, $worker): ?array {
            $run = $this->connection->fetchAssociative('SELECT * FROM place_discovery_runs WHERE id = ? FOR UPDATE', [$runId]);
            if (false === $run) {
                throw new \DomainException('Discovery run was not found.');
            }
            if (\in_array($run['status'], ['COMPLETED', 'CANCELLED'], true)) {
                return null;
            }
            $now = new \DateTimeImmutable();
            if ('RUNNING' === $run['status'] && null !== $run['lease_expires_at'] && new \DateTimeImmutable((string) $run['lease_expires_at']) > $now && $run['worker_id'] !== $worker) {
                return null;
            }
            if (!\in_array($run['status'], ['QUEUED', 'RUNNING', 'FAILED'], true)) {
                throw new \DomainException('Discovery run cannot be processed from status '.$run['status'].'.');
            }
            $stamp = $now->format(\DateTimeInterface::ATOM);
            $lease = $now->modify('+'.self::LEASE_SECONDS.' seconds')->format(\DateTimeInterface::ATOM);
            $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'RUNNING', started_at = COALESCE(started_at, ?), completed_at = NULL, worker_id = ?, last_heartbeat_at = ?, lease_expires_at = ?, transport_delivery_count = transport_delivery_count + 1, discovered_count = 0, inserted_count = 0, updated_count = 0, linked_count = 0, duplicate_count = 0, skipped_count = 0, malformed_count = 0, failed_count = 0, error_summary = NULL, error_samples = '[]'::jsonb WHERE id = ?", [$stamp, $worker, $stamp, $lease, $runId]);
            $run['status'] = 'RUNNING';

            return $run;
        });
    }

    private function heartbeat(string $runId, string $worker): void
    {
        $now = new \DateTimeImmutable();
        $this->connection->executeStatement('UPDATE place_discovery_runs SET last_heartbeat_at = ?, lease_expires_at = ? WHERE id = ? AND worker_id = ?', [$now->format(\DateTimeInterface::ATOM), $now->modify('+'.self::LEASE_SECONDS.' seconds')->format(\DateTimeInterface::ATOM), $runId, $worker]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
}
