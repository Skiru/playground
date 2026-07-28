<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Console;

use App\PlaceDiscovery\Application\DiscoveryOperationLock;
use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
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

#[AsCommand(name: 'app:place-discovery:run', description: 'Run bounded Overture place discovery for one configured area.')]
final class DiscoveryRunCommand extends Command
{
    private const RAW_FETCH_MULTIPLIER = 25;

    public function __construct(
        private readonly PlaceDiscoveryProvider $provider,
        private readonly Connection $connection,
        private readonly PlaceNormalizer $normalizer,
        private readonly FamilyDiscoveryProfile $profile,
        private readonly DiscoveryRunOrchestrator $runs,
        private readonly DiscoveryOperationLock $locks,
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
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Execute now in this process; default dispatches a QUEUED run');
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
        $release = (string) ($input->getOption('release') ?: $this->provider->getLatestRelease());
        $area = new DiscoveryArea((string) $row['id'], (string) $row['name'], (bool) $row['enabled'], (string) $row['country_code'], (float) $row['center_latitude'], (float) $row['center_longitude'], (float) $row['radius_km'], (float) $row['minimum_confidence'], (int) $row['maximum_candidates_per_run'], (string) $row['discovery_profile'], (int) $row['version']);
        if (!$input->getOption('dry-run')) {
            try {
                $runId = $this->runs->enqueue($areaId, $release, 'cli');
            } catch (\DomainException $exception) {
                $output->writeln('<error>'.$exception->getMessage().'</error>');

                return Command::FAILURE;
            }
            if (null === $runId) {
                $output->writeln('<error>An equivalent completed, queued, or active run already exists.</error>');

                return Command::FAILURE;
            }
            if ($input->getOption('sync')) {
                try {
                    $this->runs->execute($runId, $limit);
                } catch (\Throwable $exception) {
                    $output->writeln('<error>'.mb_substr($exception->getMessage(), 0, 1000).'</error>');

                    return Command::FAILURE;
                }
            } else {
                try {
                    $this->runs->dispatch($runId);
                } catch (\Throwable $exception) {
                    $output->writeln('<error>Run is persisted for reconciliation: '.mb_substr($exception->getMessage(), 0, 800).'</error>');

                    return Command::FAILURE;
                }
            }
            $payload = ['source' => $this->provider->getProviderName(), 'release' => $release, 'dry_run' => false, 'mode' => $input->getOption('sync') ? 'sync' : 'dispatch', 'run_id' => $runId];
            $output->writeln('json' === $input->getOption('output') ? json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT) : \sprintf('%s run %s for %s.', $input->getOption('sync') ? 'Completed' : 'Queued', $runId, $release));

            return Command::SUCCESS;
        }
        $lock = $this->locks->operation($this->provider->getProviderName(), $release, $areaId);
        if (!$lock->acquire()) {
            $output->writeln('<error>An equivalent run is active.</error>');

            return Command::FAILURE;
        }
        try {
            $results = [];
            $rawLimit = min(1000, max($limit, $limit * self::RAW_FETCH_MULTIPLIER));
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
                $results[] = $result;
                if (\count($results) >= $limit) {
                    break;
                }
            }
            $output->writeln('json' === $input->getOption('output') ? json_encode(['source' => 'overture', 'release' => $release, 'dry_run' => true, 'count' => \count($results), 'candidates' => $results], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) : \sprintf('Dry-run processed %d bounded candidates from %s.', \count($results), $release));
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
