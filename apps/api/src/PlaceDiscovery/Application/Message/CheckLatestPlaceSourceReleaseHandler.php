<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Message;

use App\PlaceDiscovery\Application\DiscoveryOperationLock;
use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use App\PlaceDiscovery\Application\Port\PlaceDiscoveryProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckLatestPlaceSourceReleaseHandler
{
    public function __construct(private PlaceDiscoveryProvider $provider, private Connection $connection, private DiscoveryRunOrchestrator $runs, private DiscoveryOperationLock $locks, private LoggerInterface $logger, #[Autowire('%env(bool:PLACE_DISCOVERY_ENABLED)%')] private bool $enabled)
    {
    }

    public function __invoke(CheckLatestPlaceSourceRelease $message): void
    {
        if (!$this->enabled) {
            $this->logger->info('Place discovery release check is disabled.');

            return;
        }
        $lock = $this->locks->releaseCheck($this->provider->getProviderName());
        if (!$lock->acquire()) {
            $this->logger->info('Place discovery release check already runs.');

            return;
        }
        try {
            $release = $this->provider->getLatestRelease();
            $areas = $this->connection->fetchAllAssociative('SELECT id FROM place_discovery_areas WHERE enabled = true AND last_successful_release IS DISTINCT FROM ? ORDER BY id', [$release]);
            foreach ($areas as $area) {
                $this->runs->enqueueAndDispatch((string) $area['id'], $release, 'scheduler');
            }
            $this->logger->info('Place discovery release check completed.', ['source' => $this->provider->getProviderName(), 'source_release' => $release, 'area_count' => \count($areas)]);
        } finally {
            $lock->release();
        }
    }
}
