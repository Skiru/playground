<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\UI\Console;

use App\PlaceDiscovery\Application\Message\CheckLatestPlaceSourceRelease;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:place-discovery:check-release', description: 'Resolve the current Overture release and optionally dispatch the configured check.')]
final class CheckReleaseCommand extends Command
{
    public function __construct(private readonly PlaceDiscoveryProvider $provider, private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dispatch', null, InputOption::VALUE_NONE)->addOption('output', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $release = $this->provider->getLatestRelease();
        if ($input->getOption('dispatch')) {
            $this->bus->dispatch(new CheckLatestPlaceSourceRelease());
        }
        $output->writeln('json' === $input->getOption('output') ? json_encode(['provider' => $this->provider->getProviderName(), 'release' => $release, 'dispatched' => (bool) $input->getOption('dispatch')], \JSON_THROW_ON_ERROR) : $release);

        return Command::SUCCESS;
    }
}
