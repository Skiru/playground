<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use App\Places\Application\Command\OpeningHoursModeInput;
use App\Places\Application\Command\SpecialOpeningDayModeInput;
use App\Places\Application\Command\VerificationStatusInput;
use App\Places\Domain\Place;

final class PlaceAdminFormMapper
{
    public function createData(): PlaceAdminFormData
    {
        $data = new PlaceAdminFormData();
        $data->ageZones[] = new AgeZoneFormData();

        return $data;
    }

    public function editData(Place $place): PlaceAdminFormData
    {
        $data = new PlaceAdminFormData();
        $data->expectedVersion = $place->version();
        $data->core->name = $place->name();
        $data->core->slug = $place->slug();
        $data->core->shortDescription = $place->shortDescription();
        $data->core->description = $place->description();
        $data->core->addressLine1 = $place->addressLine1();
        $data->core->addressLine2 = $place->addressLine2();
        $data->core->postalCode = $place->postalCode();
        $data->core->citySlug = $place->city()->slug();
        $data->core->countryCode = $place->countryCode();
        $data->core->latitude = $place->coordinates()->latitude;
        $data->core->longitude = $place->coordinates()->longitude;
        $data->core->timezone = $place->timezone();
        $data->core->indoor = $place->indoor();
        $data->core->outdoor = $place->outdoor();
        $data->core->freeEntry = $place->freeEntry();
        $data->core->priceDescription = $place->priceDescription();
        $data->core->websiteUrl = $place->websiteUrl();
        $data->core->phone = $place->phone();
        $data->core->verificationStatus = VerificationStatusInput::from($place->verificationStatus()->value);
        $data->categorySlugs = array_map(static fn ($category): string => $category->slug(), $place->categories());
        $data->primaryCategorySlug = $place->primaryCategory()->slug();
        $data->amenitySlugs = array_map(static fn ($amenity): string => $amenity->slug(), $place->amenities());
        $data->ageZones = array_map(static function ($zone): AgeZoneFormData {
            $item = new AgeZoneFormData();
            $item->name = $zone->name();
            $item->minAgeMonths = $zone->ageRange()->minAgeMonths;
            $item->maxAgeMonths = $zone->ageRange()->maxAgeMonths;
            $item->notes = $zone->notes();

            return $item;
        }, $place->ageZones());
        $data->openingHoursMode = OpeningHoursModeInput::from($place->openingHoursMode()->value);
        $data->weeklyOpeningHours = array_map(static function ($interval): WeeklyOpeningIntervalFormData {
            $item = new WeeklyOpeningIntervalFormData();
            $item->weekday = $interval->weekday();
            $item->opensAt = $interval->opensAt()->format('H:i');
            $item->closesAt = $interval->closesAt()->format('H:i');
            $item->closesNextDay = $interval->closesNextDay();

            return $item;
        }, $place->weeklyOpeningHours());
        $data->specialOpeningDays = array_map(static function ($day): SpecialOpeningDayFormData {
            $item = new SpecialOpeningDayFormData();
            $item->localDate = $day->localDate()->format('Y-m-d');
            $item->mode = SpecialOpeningDayModeInput::from($day->mode()->value);
            $item->note = $day->note();
            $item->intervals = array_map(static function ($interval): SpecialOpeningIntervalFormData {
                $intervalData = new SpecialOpeningIntervalFormData();
                $intervalData->opensAt = $interval->opensAt()->format('H:i');
                $intervalData->closesAt = $interval->closesAt()->format('H:i');
                $intervalData->closesNextDay = $interval->closesNextDay();

                return $intervalData;
            }, $day->intervals());

            return $item;
        }, $place->specialOpeningDays());
        $data->externalReferences = array_map(static function ($reference): ExternalReferenceFormData {
            $item = new ExternalReferenceFormData();
            $item->provider = $reference->provider();
            $item->externalId = $reference->externalId();
            $item->sourceUrl = $reference->sourceUrl();

            return $item;
        }, $place->externalReferences());

        return $data;
    }
}
