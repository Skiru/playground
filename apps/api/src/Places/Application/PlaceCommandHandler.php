<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CleanupPlacePhotoFiles;
use App\Places\Application\Command\CompletePlacePhotoProcessing;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\DeletePlacePhoto;
use App\Places\Application\Command\FailPlacePhotoProcessing;
use App\Places\Application\Command\MarkPlaceNeedsReverification;
use App\Places\Application\Command\MarkPlaceTemporarilyClosed;
use App\Places\Application\Command\ProcessPhoto;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReorderPlacePhotos;
use App\Places\Application\Command\ReplaceExternalReferences;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\ReplacePlaceAmenities;
use App\Places\Application\Command\ReplacePlaceCategories;
use App\Places\Application\Command\ReplaceSpecialOpeningDays;
use App\Places\Application\Command\ReplaceWeeklyOpeningHours;
use App\Places\Application\Command\RequestPlacePhotoReprocessing;
use App\Places\Application\Command\SetMainPlacePhoto;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UnpublishPlace;
use App\Places\Application\Command\UpdatePlaceAggregate;
use App\Places\Application\Command\UpdatePlaceCoreDetails;
use App\Places\Application\Command\UpdatePlacePhotoMetadata;
use App\Places\Application\Command\UploadPlacePhotos;
use App\Places\Application\Command\UploadPlacePhotosInput;
use App\Places\Domain\ExternalPlaceReference;
use App\Places\Domain\OpeningHoursMode;
use App\Places\Domain\Place;
use App\Places\Domain\PlaceAgeZone;
use App\Places\Domain\PlacePhoto;
use App\Places\Domain\PlacePhotoStatus;
use App\Places\Domain\PlaceStatus;
use App\Places\Domain\SpecialOpeningDay;
use App\Places\Domain\SpecialOpeningDayMode;
use App\Places\Domain\SpecialOpeningInterval;
use App\Places\Domain\ValueObject\AgeRange;
use App\Places\Domain\ValueObject\Coordinates;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use App\Places\Domain\VerificationStatus;
use App\Places\Domain\WeeklyOpeningInterval;
use App\Shared\Application\Clock;
use App\Shared\Application\Storage\StorageInterface;
use App\Shared\Application\Storage\StorageObjectKey;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PlaceCommandHandler
{
    public function __construct(
        private PlaceRepository $places,
        private TransactionManager $transactions,
        private Clock $clock,
        private StorageInterface $storage,
        private MessageBusInterface $bus,
    ) {
    }

    public function create(CreatePlaceDraft $command): string
    {
        return $this->transactions->transactional(function () use ($command): string {
            $now = $this->clock->now();
            $city = $this->places->cityBySlug($command->citySlug);
            $categorySlugs = [] === $command->categorySlugs ? [$command->categorySlug] : $command->categorySlugs;
            $categories = $this->places->categoriesBySlugs($categorySlugs);
            $primaryCategory = $this->primaryCategory($categories, $command->categorySlug);
            $amenities = $this->places->amenitiesBySlugs($command->amenitySlugs);
            $openingHoursMode = OpeningHoursMode::from($command->openingHoursMode->value);
            $place = new Place(new PlaceName($command->name), new PlaceSlug($command->slug), $command->shortDescription, $command->description, $command->addressLine1, $command->postalCode, $city, $command->countryCode, new Coordinates($command->latitude, $command->longitude), $command->timezone, $primaryCategory, $command->indoor, $command->outdoor, $command->freeEntry, $now, $command->addressLine2, $command->priceDescription, $command->websiteUrl, $command->phone, $openingHoursMode);
            $place->replaceCategories($categories, $primaryCategory, $now);
            $place->replaceAmenities($amenities, $now);
            $place->replaceAgeZones($this->ageZones($place, $command->ageZones), $now);
            $place->replaceOpeningSchedule($openingHoursMode, $this->weeklyIntervals($place, $command->weeklyOpeningHours), $this->specialDays($place, $command->specialOpeningDays), $now);
            $place->replaceExternalReferences($this->externalReferences($place, $command->externalReferences), $now);
            $this->places->add($place);

            return $place->id()->toRfc4122();
        });
    }

    public function update(UpdatePlaceAggregate $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $this->assertVersion($place, $command->expectedVersion);
            $now = $this->clock->now();
            $categories = $this->places->categoriesBySlugs($command->categorySlugs);
            $primaryCategory = $this->primaryCategory($categories, $command->primaryCategorySlug);
            $place->updateCoreDetails(new PlaceName($command->name), new PlaceSlug($command->slug), $command->shortDescription, $command->description, $command->addressLine1, $command->addressLine2, $command->postalCode, $this->places->cityBySlug($command->citySlug), $command->countryCode, new Coordinates($command->latitude, $command->longitude), $command->timezone, $command->indoor, $command->outdoor, $command->freeEntry, $command->priceDescription, $command->websiteUrl, $command->phone, VerificationStatus::from($command->verificationStatus->value), $now);
            $place->replaceCategories($categories, $primaryCategory, $now);
            $place->replaceAmenities($this->places->amenitiesBySlugs($command->amenitySlugs), $now);
            $place->replaceAgeZones($this->ageZones($place, $command->ageZones), $now);
            $place->replaceOpeningSchedule(OpeningHoursMode::from($command->openingHoursMode->value), $this->weeklyIntervals($place, $command->weeklyOpeningHours), $this->specialDays($place, $command->specialOpeningDays), $now);
            $place->replaceExternalReferences($this->externalReferences($place, $command->externalReferences), $now);
            $this->places->save($place, $command->expectedVersion);
        });
    }

    public function updateCoreDetails(UpdatePlaceCoreDetails $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $place->updateCoreDetails(new PlaceName($command->name), new PlaceSlug($command->slug), $command->shortDescription, $command->description, $command->addressLine1, $command->addressLine2, $command->postalCode, $this->places->cityBySlug($command->citySlug), $command->countryCode, new Coordinates($command->latitude, $command->longitude), $command->timezone, $command->indoor, $command->outdoor, $command->freeEntry, $command->priceDescription, $command->websiteUrl, $command->phone, VerificationStatus::from($command->verificationStatus->value), $this->clock->now());
        });
    }

    /** Granular integration use case; the administration form uses update(). */
    public function replaceCategories(ReplacePlaceCategories $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $place->replaceCategories($this->places->categoriesBySlugs($command->categorySlugs), $this->places->categoryBySlug($command->primaryCategorySlug), $this->clock->now());
        });
    }

    /** Granular integration use case; the administration form uses update(). */
    public function replaceAmenities(ReplacePlaceAmenities $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->replaceAmenities($this->places->amenitiesBySlugs($command->amenitySlugs), $this->clock->now()));
    }

    /** Granular integration use case; the administration form uses update(). */
    public function replaceAgeZones(ReplacePlaceAgeZones $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $zones = array_map(static fn ($input): PlaceAgeZone => new PlaceAgeZone($place, $input->name, new AgeRange($input->minAgeMonths, $input->maxAgeMonths), $input->notes, 'admin'), $command->ageZones);
            $place->replaceAgeZones($zones, $this->clock->now());
        });
    }

    /** Granular integration use case; the administration form uses update(). */
    public function replaceWeeklyOpeningHours(ReplaceWeeklyOpeningHours $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $intervals = array_map(fn ($input): WeeklyOpeningInterval => new WeeklyOpeningInterval($place, $input->weekday, $input->sequence, $this->time($input->opensAt), $this->time($input->closesAt), $input->closesNextDay), $command->openingHours);
            $place->replaceWeeklyOpeningHours($intervals, $this->clock->now());
        });
    }

    /** Granular integration use case; the administration form uses update(). */
    public function replaceSpecialOpeningDays(ReplaceSpecialOpeningDays $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $days = [];
            foreach ($command->specialDays as $input) {
                $day = new SpecialOpeningDay($place, new \DateTimeImmutable($input->localDate), SpecialOpeningDayMode::from($input->mode->value), $input->note);
                foreach ($input->intervals as $interval) {
                    $day->addInterval(new SpecialOpeningInterval($day, $interval->sequence, $this->time($interval->opensAt), $this->time($interval->closesAt), $interval->closesNextDay));
                }
                $days[] = $day;
            }
            $place->replaceSpecialOpeningDays($days, $this->clock->now());
        });
    }

    /** Granular integration use case; the administration form uses update(). */
    public function replaceExternalReferences(ReplaceExternalReferences $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $references = array_map(static fn ($input): ExternalPlaceReference => new ExternalPlaceReference($place, $input->provider, $input->externalId, $input->sourceUrl), $command->references);
            $place->replaceExternalReferences($references, $this->clock->now());
        });
    }

    public function submit(SubmitPlaceForReview $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->submitForReview($this->clock->now()));
    }

    public function publish(PublishPlace $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->getForUpdate($command->placeId);
            $this->assertVersion($place, $command->expectedVersion);
            if (PlaceStatus::TEMPORARILY_CLOSED === $place->status()) {
                $place->reopen($this->clock->now());
            } else {
                $place->publish($this->clock->now());
            }
            $this->places->save($place, $command->expectedVersion);
        });
    }

    public function unpublish(UnpublishPlace $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->unpublish($this->clock->now()));
    }

    public function markNeedsReverification(MarkPlaceNeedsReverification $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->markNeedsReverification($this->clock->now()));
    }

    public function markTemporarilyClosed(MarkPlaceTemporarilyClosed $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->markTemporarilyClosed($this->clock->now()));
    }

    public function archive(ArchivePlace $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->archive($this->clock->now()));
    }

    /** @param callable(Place): void $operation */
    private function mutate(string $id, int $expectedVersion, callable $operation): void
    {
        $this->transactions->transactional(function () use ($id, $expectedVersion, $operation): void {
            $place = $this->places->get($id);
            $this->assertVersion($place, $expectedVersion);
            $operation($place);
            $this->places->save($place, $expectedVersion);
        });
    }

    private function assertVersion(Place $place, int $expectedVersion): void
    {
        if ($place->version() !== $expectedVersion) {
            throw new ConcurrentPlaceModification();
        }
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable('1970-01-01 '.$value);
    }

    /** @param list<\App\Places\Domain\Category> $categories */
    private function primaryCategory(array $categories, string $slug): \App\Places\Domain\Category
    {
        foreach ($categories as $category) {
            if ($category->slug() === $slug) {
                return $category;
            }
        }

        throw new \InvalidArgumentException('Primary category must be selected as a category.');
    }

    /**
     * @param list<Command\AgeZoneInput> $inputs
     *
     * @return list<PlaceAgeZone>
     */
    private function ageZones(Place $place, array $inputs): array
    {
        return array_map(static fn ($input): PlaceAgeZone => new PlaceAgeZone($place, $input->name, new AgeRange($input->minAgeMonths, $input->maxAgeMonths), $input->notes, 'admin'), $inputs);
    }

    /**
     * @param list<Command\WeeklyOpeningIntervalInput> $inputs
     *
     * @return list<WeeklyOpeningInterval>
     */
    private function weeklyIntervals(Place $place, array $inputs): array
    {
        return array_map(fn ($input): WeeklyOpeningInterval => new WeeklyOpeningInterval($place, $input->weekday, $input->sequence, $this->time($input->opensAt), $this->time($input->closesAt), $input->closesNextDay), $inputs);
    }

    /**
     * @param list<Command\SpecialOpeningDayInput> $inputs
     *
     * @return list<SpecialOpeningDay>
     */
    private function specialDays(Place $place, array $inputs): array
    {
        $days = [];
        foreach ($inputs as $input) {
            $day = new SpecialOpeningDay($place, new \DateTimeImmutable($input->localDate), SpecialOpeningDayMode::from($input->mode->value), $input->note);
            foreach ($input->intervals as $interval) {
                $day->addInterval(new SpecialOpeningInterval($day, $interval->sequence, $this->time($interval->opensAt), $this->time($interval->closesAt), $interval->closesNextDay));
            }
            $days[] = $day;
        }

        return $days;
    }

    /**
     * @param list<Command\ExternalReferenceInput> $inputs
     *
     * @return list<ExternalPlaceReference>
     */
    private function externalReferences(Place $place, array $inputs): array
    {
        return array_map(static fn ($input): ExternalPlaceReference => new ExternalPlaceReference($place, $input->provider, $input->externalId, $input->sourceUrl), $inputs);
    }

    public function uploadPhotos(UploadPlacePhotos $command): void
    {
        $input = new UploadPlacePhotosInput($command->files);
        $errors = $input->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        $now = $this->clock->now();
        $stagedKeys = [];

        try {
            foreach ($input->images() as $image) {
                $photoId = Uuid::v7();
                $key = StorageObjectKey::source($command->placeId, $photoId->toRfc4122());

                $contents = file_get_contents($image->file()->getPathname());
                if (false === $contents) {
                    throw new \RuntimeException('Failed to read uploaded file.');
                }

                $this->storage->write($key->toString(), $contents);
                $stagedKeys[] = [
                    'id' => $photoId,
                    'key' => $key,
                    'originalName' => $image->file()->getClientOriginalName(),
                ];
            }

            $this->transactions->transactional(function () use ($command, $stagedKeys, $now): void {
                $place = $this->places->get($command->placeId);
                $currentPhotosCount = \count($place->photos());

                foreach ($stagedKeys as $index => $staged) {
                    $isMain = (0 === $currentPhotosCount && 0 === $index);
                    $photo = PlacePhoto::reconstitute(
                        $staged['id'],
                        $place,
                        $staged['originalName'],
                        $staged['key']->toString(),
                        PlacePhotoStatus::QUEUED,
                        $isMain,
                        $currentPhotosCount + $index,
                        null,
                        null,
                        null,
                        1,
                        null,
                        null,
                        $now,
                        $now
                    );

                    $place->addPhoto($photo, $now);
                    $this->bus->dispatch(new ProcessPhoto($staged['id']->toRfc4122()));
                }

                $this->places->save($place, $place->version());
            });
        } catch (\Throwable $exception) {
            foreach ($stagedKeys as $staged) {
                $this->storage->delete($staged['key']->toString());
            }
            throw $exception;
        }
    }

    public function setMainPhoto(SetMainPlacePhoto $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            $photoExists = false;
            $photoIsCompleted = false;

            foreach ($place->photos() as $photo) {
                if ($photo->id()->toRfc4122() === $command->photoId) {
                    $photoExists = true;
                    if (PlacePhotoStatus::COMPLETED === $photo->status()) {
                        $photoIsCompleted = true;
                    }
                    break;
                }
            }

            if (!$photoExists) {
                throw new \InvalidArgumentException('Target photo does not exist.');
            }

            if (!$photoIsCompleted) {
                throw new \DomainException('Only COMPLETED photos can be set as main.');
            }

            foreach ($place->photos() as $photo) {
                $isTarget = $photo->id()->toRfc4122() === $command->photoId;
                $photo->setMain($isTarget, $now);
            }

            $this->places->save($place, $place->version());
        });
    }

    public function updatePhotoMetadata(UpdatePlacePhotoMetadata $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            $targetPhoto = null;
            foreach ($place->photos() as $photo) {
                if ($photo->id()->toRfc4122() === $command->photoId) {
                    $targetPhoto = $photo;
                    break;
                }
            }

            if (!$targetPhoto) {
                throw new \InvalidArgumentException('Photo not found.');
            }

            $altText = null !== $command->altText ? preg_replace('/\s+/', ' ', trim($command->altText)) : null;
            if ('' === $altText) {
                $altText = null;
            }
            if (null !== $altText && mb_strlen($altText) > 255) {
                throw new \InvalidArgumentException('Alt text cannot exceed 255 characters.');
            }

            $caption = null !== $command->caption ? preg_replace('/\s+/', ' ', trim($command->caption)) : null;
            if ('' === $caption) {
                $caption = null;
            }
            if (null !== $caption && mb_strlen($caption) > 500) {
                throw new \InvalidArgumentException('Caption cannot exceed 500 characters.');
            }

            $targetPhoto->updateDetails($altText, $caption, $command->displayOrder, $now);
            $this->places->save($place, $place->version());
        });
    }

    public function reorderPlacePhotos(ReorderPlacePhotos $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            $existingPhotos = $place->photos();
            $existingIds = array_map(static fn (PlacePhoto $p): string => $p->id()->toRfc4122(), $existingPhotos);

            if (\count(array_unique($command->photoIds)) !== \count($command->photoIds)) {
                throw new \InvalidArgumentException('Duplicate photo IDs detected.');
            }

            if (\count($command->photoIds) !== \count($existingIds)) {
                throw new \InvalidArgumentException('Reorder list must contain all photos belonging to this place.');
            }

            foreach ($command->photoIds as $photoId) {
                if (!\in_array($photoId, $existingIds, true)) {
                    throw new \InvalidArgumentException(\sprintf('Photo with ID "%s" does not belong to this place.', $photoId));
                }
            }

            foreach ($existingPhotos as $photo) {
                $index = array_search($photo->id()->toRfc4122(), $command->photoIds, true);
                $photo->updateDetails($photo->altText(), $photo->caption(), (int) $index, $now);
            }

            $this->places->save($place, $place->version());
        });
    }

    public function requestPlacePhotoReprocessing(RequestPlacePhotoReprocessing $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            $targetPhoto = null;
            foreach ($place->photos() as $photo) {
                if ($photo->id()->toRfc4122() === $command->photoId) {
                    $targetPhoto = $photo;
                    break;
                }
            }

            if (!$targetPhoto) {
                throw new \InvalidArgumentException('Photo not found.');
            }

            $targetPhoto->incrementGeneration($now);
            $this->places->save($place, $place->version());

            $this->bus->dispatch(new ProcessPhoto($command->photoId));
        });
    }

    public function deletePlacePhoto(DeletePlacePhoto $command): void
    {
        $photoToDelete = null;

        $this->transactions->transactional(function () use ($command, &$photoToDelete): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            foreach ($place->photos() as $photo) {
                if ($photo->id()->toRfc4122() === $command->photoId) {
                    $photoToDelete = $photo;
                    $photo->markDeleting($now);
                    break;
                }
            }

            if ($photoToDelete) {
                $this->places->save($place, $place->version());
                $this->bus->dispatch(new CleanupPlacePhotoFiles($command->placeId, $command->photoId));
            }
        });
    }

    public function completePhotoProcessing(CompletePlacePhotoProcessing $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            foreach ($place->photos() as $photo) {
                if ($photo->id()->toRfc4122() === $command->photoId) {
                    if (PlacePhotoStatus::DELETING === $photo->status()) {
                        return;
                    }
                    if ($photo->processingGeneration() !== $command->generation) {
                        return;
                    }
                    $photo->markCompleted($command->generation, $command->variants, $now);
                    break;
                }
            }

            $this->places->save($place, $place->version());
        });
    }

    public function failPhotoProcessing(FailPlacePhotoProcessing $command): void
    {
        $this->transactions->transactional(function () use ($command): void {
            $place = $this->places->get($command->placeId);
            $now = $this->clock->now();

            foreach ($place->photos() as $photo) {
                if ($photo->id()->toRfc4122() === $command->photoId) {
                    if (PlacePhotoStatus::DELETING === $photo->status()) {
                        return;
                    }
                    if ($photo->processingGeneration() !== $command->generation) {
                        return;
                    }
                    $photo->markFailed($command->generation, $command->failureCode, $now);
                    break;
                }
            }

            $this->places->save($place, $place->version());
        });
    }
}
