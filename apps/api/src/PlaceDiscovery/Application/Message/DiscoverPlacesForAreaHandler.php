<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application\Message;

use App\PlaceDiscovery\Application\DiscoveryRunOrchestrator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DiscoverPlacesForAreaHandler
{
    public function __construct(private DiscoveryRunOrchestrator $runs, #[Autowire('%env(bool:PLACE_DISCOVERY_ENABLED)%')] private bool $enabled)
    {
    }

    public function __invoke(DiscoverPlacesForArea $message): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->runs->execute($message->runId);
    }
}
