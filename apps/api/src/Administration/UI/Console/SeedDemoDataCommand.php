<?php

declare(strict_types=1);

namespace App\Administration\UI\Console;

use App\Places\Infrastructure\Fixtures\PlacesFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:db:seed', description: 'Seed database with demo cities, categories, amenities, places, and admin user.')]
final class SeedDemoDataCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $fixtures = new PlacesFixtures($this->connection);
            $fixtures->load();

            $discoveryFixtures = new \App\PlaceDiscovery\Infrastructure\Fixtures\PlaceDiscoveryFixtures($this->connection);
            $discoveryFixtures->load();

            $io->success('Database successfully seeded with demo data and administrator account.');

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error('Failed to seed database: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }
}
