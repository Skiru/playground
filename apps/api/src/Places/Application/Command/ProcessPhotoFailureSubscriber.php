<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use App\Places\Application\PlaceRepository;
use App\Places\Domain\PlacePhotoStatus;
use App\Shared\Application\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final readonly class ProcessPhotoFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private PlaceRepository $places,
        private Clock $clock,
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
                if (false !== $placeId) {
                    $place = $this->places->get((string) $placeId);
                    foreach ($place->photos() as $photo) {
                        if ($photo->id()->toRfc4122() === $photoId) {
                            if ($photo->status() !== PlacePhotoStatus::COMPLETED && $photo->status() !== PlacePhotoStatus::DELETING) {
                                $photo->markFailed($photo->processingGeneration(), 'PROCESSING_RETRY_EXHAUSTED', $this->clock->now());
                                $this->places->save($place, $place->version());
                            }
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                // Prevent breaking failure queue/transport if anything fails
            }
        }
    }
}
