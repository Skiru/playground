<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Console;

use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:place-discovery:retry', description: 'Retry one failed or partial bounded discovery run.')]
final class RetryDiscoveryRunCommand extends Command
{
    public function __construct(private readonly DiscoveryRunOrchestrator $runs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run', InputArgument::REQUIRED, 'Failed/partial run UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $runId = $this->runs->retry((string) $input->getArgument('run'), 'cli');
        } catch (\DomainException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::INVALID;
        }
        $output->writeln('Retry dispatched as run '.$runId.'.');

        return Command::SUCCESS;
    }
}
