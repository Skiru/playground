<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\MarkPlaceNeedsReverification;
use App\Places\Application\Command\MarkPlaceTemporarilyClosed;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReplaceExternalReferences;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\ReplacePlaceAmenities;
use App\Places\Application\Command\ReplacePlaceCategories;
use App\Places\Application\Command\ReplaceSpecialOpeningDays;
use App\Places\Application\Command\ReplaceWeeklyOpeningHours;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UnpublishPlace;
use App\Places\Application\Command\UpdatePlaceCoreDetails;
use App\Places\Domain\ExternalPlaceReference;
use App\Places\Domain\Place;
use App\Places\Domain\PlaceAgeZone;
use App\Places\Domain\PlaceStatus;
use App\Places\Domain\SpecialOpeningDay;
use App\Places\Domain\SpecialOpeningInterval;
use App\Places\Domain\ValueObject\AgeRange;
use App\Places\Domain\ValueObject\Coordinates;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use App\Places\Domain\WeeklyOpeningInterval;
use App\Shared\Application\Clock;
use App\Shared\Application\TransactionManager;

final readonly class PlaceCommandHandler
{
    public function __construct(private PlaceRepository $places, private TransactionManager $transactions, private Clock $clock)
    {
    }

    public function create(CreatePlaceDraft $command): string
    {
        return $this->transactions->transactional(function () use ($command): string {
            $now = $this->clock->now();
            $city = $this->places->cityBySlug($command->citySlug);
            $category = $this->places->categoryBySlug($command->categorySlug);
            $place = new Place(new PlaceName($command->name), new PlaceSlug($command->slug), $command->shortDescription, $command->description, $command->addressLine1, $command->postalCode, $city, $command->countryCode, new Coordinates($command->latitude, $command->longitude), $command->timezone, $category, $command->indoor, $command->outdoor, $command->freeEntry, $now, $command->addressLine2, $command->priceDescription, $command->websiteUrl, $command->phone);
            $this->places->add($place);

            return $place->id()->toRfc4122();
        });
    }

    public function updateCoreDetails(UpdatePlaceCoreDetails $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $place->updateCoreDetails(new PlaceName($command->name), new PlaceSlug($command->slug), $command->shortDescription, $command->description, $command->addressLine1, $command->addressLine2, $command->postalCode, $this->places->cityBySlug($command->citySlug), $command->countryCode, new Coordinates($command->latitude, $command->longitude), $command->timezone, $command->indoor, $command->outdoor, $command->freeEntry, $command->priceDescription, $command->websiteUrl, $command->phone, $command->verificationStatus, $this->clock->now());
        });
    }

    public function replaceCategories(ReplacePlaceCategories $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $place->replaceCategories($this->places->categoriesBySlugs($command->categorySlugs), $this->places->categoryBySlug($command->primaryCategorySlug), $this->clock->now());
        });
    }

    public function replaceAmenities(ReplacePlaceAmenities $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, fn (Place $place) => $place->replaceAmenities($this->places->amenitiesBySlugs($command->amenitySlugs), $this->clock->now()));
    }

    public function replaceAgeZones(ReplacePlaceAgeZones $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $zones = array_map(static fn ($input): PlaceAgeZone => new PlaceAgeZone($place, $input->name, new AgeRange($input->minAgeMonths, $input->maxAgeMonths), $input->notes, 'admin'), $command->ageZones);
            $place->replaceAgeZones($zones, $this->clock->now());
        });
    }

    public function replaceWeeklyOpeningHours(ReplaceWeeklyOpeningHours $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $intervals = array_map(fn ($input): WeeklyOpeningInterval => new WeeklyOpeningInterval($place, $input->weekday, $input->sequence, $this->time($input->opensAt), $this->time($input->closesAt), $input->closesNextDay), $command->openingHours);
            $place->replaceWeeklyOpeningHours($intervals, $this->clock->now());
        });
    }

    public function replaceSpecialOpeningDays(ReplaceSpecialOpeningDays $command): void
    {
        $this->mutate($command->placeId, $command->expectedVersion, function (Place $place) use ($command): void {
            $days = [];
            foreach ($command->specialDays as $input) {
                $day = new SpecialOpeningDay($place, new \DateTimeImmutable($input->localDate), $input->closed, $input->note);
                foreach ($input->intervals as $interval) {
                    $day->addInterval(new SpecialOpeningInterval($day, $interval->sequence, $this->time($interval->opensAt), $this->time($interval->closesAt), $interval->closesNextDay));
                }
                $days[] = $day;
            }
            $place->replaceSpecialOpeningDays($days, $this->clock->now());
        });
    }

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
}
