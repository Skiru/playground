<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Console;

use App\PlaceDiscovery\Application\Message\DiscoverPlacesForArea;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:place-discovery:retry', description: 'Retry one failed or partial bounded discovery run.')]
final class RetryDiscoveryRunCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run', InputArgument::REQUIRED, 'Failed/partial run UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $run = $this->connection->fetchAssociative("SELECT source, area_id, source_release FROM place_discovery_runs WHERE id = ? AND status IN ('FAILED','PARTIAL')", [(string) $input->getArgument('run')]);
        if (false === $run) {
            $output->writeln('<error>Only failed or partial runs may be retried.</error>');

            return Command::INVALID;
        }
        $attempt = 1 + (int) $this->connection->fetchOne('SELECT COALESCE(MAX(attempt), 0) FROM place_discovery_runs WHERE source = ? AND source_release = ? AND area_id = ?', [$run['source'], $run['source_release'], $run['area_id']]);
        $this->bus->dispatch(new DiscoverPlacesForArea((string) $run['area_id'], (string) $run['source_release'], $attempt));
        $output->writeln('Retry dispatched.');

        return Command::SUCCESS;
    }
}
