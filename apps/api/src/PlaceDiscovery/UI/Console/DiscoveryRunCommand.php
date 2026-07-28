<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Console;

use App\PlaceDiscovery\Application\PlaceDiscoveryService;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:place-discovery:run', description: 'Run bounded Overture place discovery for one configured area.')]
final class DiscoveryRunCommand extends Command
{
    public function __construct(
        private readonly PlaceDiscoveryProvider $provider,
        private readonly Connection $connection,
        private readonly PlaceNormalizer $normalizer,
        private readonly FamilyDiscoveryProfile $profile,
        private readonly PlaceDiscoveryService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('area', null, InputOption::VALUE_REQUIRED, 'Discovery area UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Process provider records without database mutations')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records', '20')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'Overture release; defaults to latest')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Run synchronously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $areaId = (string) $input->getOption('area');
        $row = $this->connection->fetchAssociative('SELECT * FROM place_discovery_areas WHERE id = ?', [$areaId]);
        if (false === $row) {
            $output->writeln('<error>Discovery area was not found.</error>');

            return Command::INVALID;
        }
        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => min(1000, (int) $row['maximum_candidates_per_run'])]]);
        if (false === $limit) {
            $output->writeln('<error>Invalid bounded limit.</error>');

            return Command::INVALID;
        }
        $lockKey = 'place-discovery:'.$areaId.':'.($input->getOption('release') ?: 'latest');
        if (!$this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$lockKey])) {
            $output->writeln('<error>An equivalent run is active.</error>');

            return Command::FAILURE;
        }
        try {
            $release = (string) ($input->getOption('release') ?: $this->provider->getLatestRelease());
            $area = new DiscoveryArea((string) $row['id'], (string) $row['name'], (bool) $row['enabled'], (string) $row['country_code'], (float) $row['center_latitude'], (float) $row['center_longitude'], (float) $row['radius_km'], (float) $row['minimum_confidence'], (int) $row['maximum_candidates_per_run'], (string) $row['discovery_profile'], (int) $row['version']);
            $runId = Uuid::v7()->toRfc4122();
            if (!$input->getOption('dry-run')) {
                $attempt = 1 + (int) $this->connection->fetchOne('SELECT COALESCE(MAX(attempt), 0) FROM place_discovery_runs WHERE source = ? AND source_release = ? AND area_id = ?', [$this->provider->getProviderName(), $release, $areaId]);
                $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $this->connection->insert('place_discovery_runs', ['id' => $runId, 'source' => $this->provider->getProviderName(), 'source_release' => $release, 'area_id' => $areaId, 'attempt' => $attempt, 'status' => 'RUNNING', 'requested_by' => 'cli', 'started_at' => $now, 'created_at' => $now]);
            }
            $results = [];
            $rawLimit = min(1000, max($limit, $limit * 25));
            foreach ($this->provider->streamPlaces($area, $area->profile, $release, $rawLimit) as $place) {
                if (null !== $place->confidence && $place->confidence < $area->minimumConfidence) {
                    continue;
                }
                $normalized = $this->normalizer->normalize($place);
                $classification = $this->profile->classify($place, $normalized);
                if (!$classification->discoverable) {
                    continue;
                }
                $result = ['external_id' => $place->externalId, 'name' => $normalized->name, 'status' => $classification->status->value, 'score' => $classification->score, 'category' => $classification->category, 'reasons' => $classification->reasons];
                if (!$input->getOption('dry-run')) {
                    $result['result'] = $this->service->import($runId, $place, $normalized, $classification);
                }
                $results[] = $result;
                if (\count($results) >= $limit) {
                    break;
                }
            }
            if (!$input->getOption('dry-run')) {
                $this->connection->executeStatement("UPDATE place_discovery_runs SET status = 'COMPLETED', completed_at = ?, discovered_count = ? WHERE id = ?", [(new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), \count($results), $runId]);
            }
            $output->writeln('json' === $input->getOption('output') ? json_encode(['source' => 'overture', 'release' => $release, 'dry_run' => (bool) $input->getOption('dry-run'), 'count' => \count($results), 'candidates' => $results], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) : \sprintf('Processed %d bounded candidates from %s.', \count($results), $release));
        } finally {
            $this->connection->executeStatement('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
        }

        return Command::SUCCESS;
    }
}
