<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use App\Places\Application\PlaceCommandHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final readonly class ProcessPhotoFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private PlaceCommandHandler $commandHandler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();

        if ($message instanceof ProcessPhoto) {
            $photoId = $message->photoId;
            try {
                $placeId = $this->connection->fetchOne('SELECT place_id FROM place_photos WHERE id = :id', ['id' => $photoId]);
                $generation = $this->connection->fetchOne('SELECT processing_generation FROM place_photos WHERE id = :id', ['id' => $photoId]);
                if (false !== $placeId && false !== $generation) {
                    $this->commandHandler->failPhotoProcessing(new FailPlacePhotoProcessing(
                        (string) $placeId,
                        $photoId,
                        (int) $generation,
                        'PROCESSING_RETRIES_EXHAUSTED'
                    ));
                }
            } catch (\Throwable) {
                // Prevent breaking failure queue/transport if anything fails
            }
        }
    }
}
