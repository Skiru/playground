<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use App\Shared\Application\Storage\ImageProcessor;
use App\Shared\Application\Storage\StorageInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessPhotoHandler
{
    public function __construct(
        private Connection $connection,
        private StorageInterface $storage,
        private ImageProcessor $processor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPhoto $command): void
    {
        $photoId = $command->photoId;
        $row = $this->connection->fetchAssociative('SELECT * FROM place_photos WHERE id = :id', ['id' => $photoId]);
        if (false === $row) {
            $this->logger->error('Photo not found for processing', ['photo_id' => $photoId]);

            return;
        }

        $placeId = (string) $row['place_id'];
        $filePath = (string) $row['file_path'];

        try {
            // Read original image
            $originalBytes = $this->storage->read($filePath);

            $widths = [
                'thumbnail_mini' => 150,
                'thumbnail' => 400,
                'card' => 800,
                'hero' => 1200,
                'original' => 1920,
            ];

            $variants = [];
            foreach ($widths as $name => $width) {
                // Resize using our ImageProcessor
                $resizedBytes = $this->processor->resizeToWebp($originalBytes, $width);

                // Define path
                $variantPath = \sprintf('places/%s/photos/%s/%s.webp', $placeId, $photoId, $name);

                // Write to storage
                $this->storage->write($variantPath, $resizedBytes);

                // Store public URL/path
                $variants[$name] = $this->storage->getUrl($variantPath);
            }

            // Update database status
            $this->connection->update('place_photos', [
                'status' => 'completed',
                'variants' => json_encode($variants, \JSON_THROW_ON_ERROR),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], ['id' => $photoId]);

            $this->logger->info('Photo processed successfully', ['photo_id' => $photoId, 'variants' => $variants]);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to process photo', [
                'photo_id' => $photoId,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $this->connection->update('place_photos', [
                'status' => 'failed',
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], ['id' => $photoId]);
        }
    }
}
