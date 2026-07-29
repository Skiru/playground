<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Console;

use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:place-discovery:reconcile-dispatch', description: 'Deliver bounded pending PlaceDiscovery outbox entries.')]
final class ReconcileDiscoveryDispatchCommand extends Command
{
    public function __construct(private readonly DiscoveryRunOrchestrator $runs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum pending runs', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if (false === $limit) {
            $output->writeln('<error>Invalid reconciliation limit.</error>');

            return Command::INVALID;
        }
        try {
            $count = $this->runs->reconcilePendingDispatches($limit);
        } catch (\DomainException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln('Dispatched '.$count.' pending discovery run(s).');

        return Command::SUCCESS;
    }
}
