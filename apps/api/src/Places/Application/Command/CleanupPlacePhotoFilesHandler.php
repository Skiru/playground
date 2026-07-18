<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

use App\Places\Application\PlaceRepository;
use App\Places\Domain\PlacePhotoStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupPlacePhotoFilesHandler
{
    public function __construct(
        private PlaceRepository $places,
        private TransactionManager $transactions,
        private StorageInterface $storage,
        private Clock $clock,
    ) {
    }

    public function __invoke(CleanupPlacePhotoFiles $command): void
    {
        $placeId = $command->placeId;
        $photoId = $command->photoId;

        // 1. Load place and find photo outside transaction (for check)
        $place = $this->places->get($placeId);
        $photo = null;
        foreach ($place->photos() as $p) {
            if ($p->id()->toRfc4122() === $photoId) {
                $photo = $p;
                break;
            }
        }

        // 2. Brak rekordu -> sukces
        if (!$photo) {
            return;
        }

        // 3. Status inny niż DELETING -> controlled no-op
        if (PlacePhotoStatus::DELETING !== $photo->status()) {
            return;
        }

        $filePath = $photo->filePath();
        $generation = $photo->processingGeneration();

        // 4. Usuń source
        $this->storage->delete($filePath);

        // 5. Usuń wszystkie warianty wszystkich generacji
        $variants = ['thumbnail_mini', 'thumbnail', 'card', 'hero', 'original_max'];
        for ($g = 1; $g <= $generation; ++$g) {
            foreach ($variants as $v) {
                $variantKey = StorageObjectKey::variant($placeId, $photoId, $g, $v);
                $this->storage->delete($variantKey->toString());
            }
        }

        // 6. Po sukcesie: transakcja i usuń z agregatu + set main photo if needed
        $this->transactions->transactional(function () use ($placeId, $photoId): void {
            $place = $this->places->get($placeId);
            $photoToDelete = null;
            $cleanedPhotos = [];

            foreach ($place->photos() as $p) {
                if ($p->id()->toRfc4122() === $photoId) {
                    $photoToDelete = $p;
                } else {
                    $cleanedPhotos[] = $p;
                }
            }

            // Brak rekordu w transakcji -> sukces
            if (!$photoToDelete) {
                return;
            }

            $now = $this->clock->now();

            // Jeżeli usuwane było main, wybierz pierwsze COMPLETED po display order
            if ($photoToDelete->isMain() && \count($cleanedPhotos) > 0) {
                $newMain = null;
                // Sort cleaned photos by display order first to ensure correct selection
                usort($cleanedPhotos, static fn ($a, $b) => $a->displayOrder() <=> $b->displayOrder());

                foreach ($cleanedPhotos as $p) {
                    if (PlacePhotoStatus::COMPLETED === $p->status()) {
                        $newMain = $p;
                        break;
                    }
                }
                if ($newMain) {
                    $newMain->setMain(true, $now);
                }
            }

            $place->replacePhotos($cleanedPhotos, $now);
            $this->places->save($place, $place->version());
        });
    }
}
