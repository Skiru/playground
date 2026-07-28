<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Message;

use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class DiscoverPlacesForAreaHandler
{
    public function __construct(private Connection $connection, private PlaceDiscoveryProvider $provider, private PlaceNormalizer $normalizer, private FamilyDiscoveryProfile $profile, private PlaceDiscoveryService $service, private LockFactory $locks, private LoggerInterface $logger, #[Autowire('%env(bool:PLACE_DISCOVERY_ENABLED)%')] private bool $enabled)
    {
    }

    public function __invoke(DiscoverPlacesForArea $message): void
    {
        if (!$this->enabled) {
            return;
        }
        $lock = $this->locks->createLock('place-discovery:area:'.$message->areaId.':'.$message->release, 3600);
        if (!$lock->acquire()) {
            return;
        }
        $runId = Uuid::v7()->toRfc4122();
        $counts = ['inserted' => 0, 'updated' => 0, 'linked' => 0];
        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM place_discovery_areas WHERE id = ? AND enabled = true', [$message->areaId]);
            if (false === $row) {
                return;
            }
            $area = new DiscoveryArea((string) $row['id'], (string) $row['name'], true, (string) $row['country_code'], (float) $row['center_latitude'], (float) $row['center_longitude'], (float) $row['radius_km'], (float) $row['minimum_confidence'], (int) $row['maximum_candidates_per_run'], (string) $row['discovery_profile'], (int) $row['version']);
            try {
                $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $this->connection->insert('place_discovery_runs', ['id' => $runId, 'source' => 'overture', 'source_release' => $message->release, 'area_id' => $message->areaId, 'status' => 'RUNNING', 'attempt' => $message->attempt, 'started_at' => $now, 'created_at' => $now]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                return;
            }
            $rawLimit = min(1000, $area->maximumCandidatesPerRun * 25);
            foreach ($this->provider->streamPlaces($area, $area->profile, $message->release, $rawLimit) as $place) {
                if (null !== $place->confidence && $place->confidence < $area->minimumConfidence) {
                    continue;
                }
                $normalized = $this->normalizer->normalize($place);
                $classification = $this->profile->classify($place, $normalized);
                if (!$classification->discoverable) {
                    continue;
                }
                ++$counts[$this->service->import($runId, $place, $normalized, $classification)];
                if (array_sum($counts) >= $area->maximumCandidatesPerRun) {
                    break;
                }
            }
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'COMPLETED', completed_at = ?, discovered_count = ?, inserted_count = ?, updated_count = ? WHERE id = ?", [$now, array_sum($counts), $counts['inserted'], $counts['updated'] + $counts['linked'], $runId]);
            $this->connection->executeStatement('UPDATE place_discovery_areas SET last_successful_release = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$message->release, $now, $message->areaId]);
            $this->logger->info('Place discovery area completed.', ['discovery_run_id' => $runId, 'source' => 'overture', 'source_release' => $message->release, 'area_id' => $message->areaId, 'counters' => $counts]);
        } catch (\Exception $exception) {
            $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'FAILED', completed_at = ?, failed_count = failed_count + 1, error_summary = ? WHERE id = ?", [(new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), mb_substr($exception->getMessage(), 0, 2000), $runId]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
