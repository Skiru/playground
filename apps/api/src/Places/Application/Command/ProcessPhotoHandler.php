<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use App\Places\Application\PlaceRepository;
use App\Places\Domain\PlacePhotoStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Storage\CorruptImageException;
use App\Shared\Application\Storage\ImageProcessor;
use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;
use App\Shared\Application\Storage\StorageObjectNotFoundException;
use App\Shared\Application\Storage\UnsupportedImageException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessPhotoHandler
{
    public function __construct(
        private Connection $connection,
        private PlaceRepository $places,
        private StorageInterface $storage,
        private ImageProcessor $processor,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPhoto $command): void
    {
        $photoId = $command->photoId;

        // 1. Fetch placeId using a narrow non-SELECT * query to avoid DBAL aggregate bypass
        $placeId = $this->connection->fetchOne('SELECT place_id FROM place_photos WHERE id = :id', ['id' => $photoId]);
        if (false === $placeId) {
            $this->logger->info('Photo not found in database, ignoring.', ['photo_id' => $photoId]);

            return;
        }

        // Load the Place aggregate using the repository
        $place = $this->places->get((string) $placeId);
        $photo = null;
        foreach ($place->photos() as $p) {
            if ($p->id()->toRfc4122() === $photoId) {
                $photo = $p;
                break;
            }
        }

        if (!$photo) {
            $this->logger->info('Photo not found in aggregate, ignoring.', ['photo_id' => $photoId]);

            return;
        }

        // 2. Check if DELETING -> idempotent no-op
        if (PlacePhotoStatus::DELETING === $photo->status()) {
            $this->logger->info('Photo is deleting, ignoring.', ['photo_id' => $photoId]);

            return;
        }

        $generation = $photo->processingGeneration();
        $now = $this->clock->now();

        // 5. Mark PROCESSING and save aggregate once before starting
        $photo->startProcessing($generation, $now);
        $this->places->save($place, $place->version());

        $writtenKeys = [];

        try {
            // 6. Read private source file
            $originalBytes = $this->storage->read($photo->filePath());

            $widths = [
                'thumbnail_mini' => 150,
                'thumbnail' => 400,
                'card' => 800,
                'hero' => 1200,
                'original_max' => 1920,
            ];

            $variants = [];

            // 7. Generate variants
            foreach ($widths as $name => $width) {
                $resizedBytes = $this->processor->resizeToWebp($originalBytes, $width);

                $variantKey = StorageObjectKey::variant((string) $placeId, $photoId, $generation, $name);

                // 8. Write variant to storage under generation-specific key
                $this->storage->write($variantKey->toString(), $resizedBytes);
                $writtenKeys[] = $variantKey->toString();

                $info = getimagesizefromstring($resizedBytes);
                if (false === $info) {
                    throw new CorruptImageException('Failed to get dimensions of resized image variant.');
                }
                $w = $info[0];
                $h = $info[1];

                $variants[$name] = [
                    'key' => $variantKey->toString(),
                    'width' => $w,
                    'height' => $h,
                    'mediaType' => 'image/webp',
                    'byteSize' => \strlen($resizedBytes),
                    'generation' => $generation,
                ];
            }

            // Reload place to check for any concurrent modifications/deletes during processing
            $place = $this->places->get((string) $placeId);
            $photo = null;
            foreach ($place->photos() as $p) {
                if ($p->id()->toRfc4122() === $photoId) {
                    $photo = $p;
                    break;
                }
            }

            // 9. Check generation and status again
            if (!$photo || PlacePhotoStatus::DELETING === $photo->status() || $photo->processingGeneration() !== $generation) {
                // Stale generation or deleting -> cleanup and return
                foreach ($writtenKeys as $key) {
                    $this->storage->delete($key);
                }

                return;
            }

            // 10. Atomic save COMPLETED status and variants map
            $photo->markCompleted($generation, $variants, $this->clock->now());
            $this->places->save($place, $place->version());

            $this->logger->info('Photo processed successfully.', ['photo_id' => $photoId, 'generation' => $generation]);
        } catch (\Throwable $exception) {
            // Clean up any partially written variants
            foreach ($writtenKeys as $key) {
                try {
                    $this->storage->delete($key);
                } catch (\Throwable) {
                    // Ignore failures during cleanup
                }
            }

            // Determine failureCode and isPermanent
            $isPermanent = false;
            $failureCode = 'UNKNOWN_FAILURE';

            if ($exception instanceof CorruptImageException) {
                $isPermanent = true;
                $failureCode = 'CORRUPT_IMAGE';
            } elseif ($exception instanceof UnsupportedImageException) {
                $isPermanent = true;
                $failureCode = 'UNSUPPORTED_IMAGE';
            } elseif ($exception instanceof StorageObjectNotFoundException) {
                $isPermanent = true;
                $failureCode = 'SOURCE_NOT_FOUND';
            }

            // Log details as per section 5.3
            $this->logger->error('Processing failure.', [
                'photo_id' => $photoId,
                'place_id' => (string) $placeId,
                'generation' => $generation,
                'failure_code' => $failureCode,
                'exception_class' => $exception::class,
            ]);

            // If permanent processing failure, mark FAILED and don't retry
            if ($isPermanent) {
                $place = $this->places->get((string) $placeId);
                foreach ($place->photos() as $p) {
                    if ($p->id()->toRfc4122() === $photoId) {
                        $p->markFailed($generation, $failureCode, $this->clock->now());
                        break;
                    }
                }
                $this->places->save($place, $place->version());

                return;
            }

            // Rethrow unexpected/retryable failures for Messenger retry
            throw $exception;
        }
    }
}
