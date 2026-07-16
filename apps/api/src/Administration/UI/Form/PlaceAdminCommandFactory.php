<?php

declare(strict_types=1);

namespace App\Administration\UI\Form;

use App\Places\Application\Command\AgeZoneInput;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\Command\SpecialOpeningDayInput;
use App\Places\Application\Command\SpecialOpeningIntervalInput;
use App\Places\Application\Command\UpdatePlaceAggregate;
use App\Places\Application\Command\WeeklyOpeningIntervalInput;

final class PlaceAdminCommandFactory
{
    public function create(PlaceAdminFormData $data): CreatePlaceDraft
    {
        $core = $data->core;

        return new CreatePlaceDraft($core->name, $core->slug, $core->shortDescription, $core->description, $core->addressLine1, $core->postalCode, $core->citySlug, $core->countryCode, $core->latitude, $core->longitude, $core->timezone, $data->primaryCategorySlug, $core->indoor, $core->outdoor, $core->freeEntry, $core->addressLine2, $core->priceDescription, $core->websiteUrl, $core->phone, $data->categorySlugs, $data->amenitySlugs, self::ageZones($data), $data->openingHoursMode, self::weekly($data), self::special($data), self::references($data));
    }

    public function update(string $placeId, PlaceAdminFormData $data): UpdatePlaceAggregate
    {
        if (null === $data->expectedVersion) {
            throw new \InvalidArgumentException('Expected version is required for editing.');
        }
        $core = $data->core;

        return new UpdatePlaceAggregate($placeId, $data->expectedVersion, $core->name, $core->slug, $core->shortDescription, $core->description, $core->addressLine1, $core->postalCode, $core->citySlug, $core->countryCode, $core->latitude, $core->longitude, $core->timezone, $core->indoor, $core->outdoor, $core->freeEntry, $core->verificationStatus, $data->categorySlugs, $data->primaryCategorySlug, $data->amenitySlugs, self::ageZones($data), $data->openingHoursMode, self::weekly($data), self::special($data), self::references($data), $core->addressLine2, $core->priceDescription, $core->websiteUrl, $core->phone);
    }

    /** @return list<AgeZoneInput> */
    private static function ageZones(PlaceAdminFormData $data): array
    {
        return array_map(static fn (AgeZoneFormData $zone): AgeZoneInput => new AgeZoneInput($zone->name, $zone->minAgeMonths, $zone->maxAgeMonths, $zone->notes), $data->ageZones);
    }

    /** @return list<WeeklyOpeningIntervalInput> */
    private static function weekly(PlaceAdminFormData $data): array
    {
        $sequences = [];

        return array_map(static function (WeeklyOpeningIntervalFormData $interval) use (&$sequences): WeeklyOpeningIntervalInput {
            $sequence = ($sequences[$interval->weekday] ?? 0) + 1;
            $sequences[$interval->weekday] = $sequence;

            return new WeeklyOpeningIntervalInput($interval->weekday, $sequence, $interval->opensAt, $interval->closesAt, $interval->closesNextDay);
        }, $data->weeklyOpeningHours);
    }

    /** @return list<SpecialOpeningDayInput> */
    private static function special(PlaceAdminFormData $data): array
    {
        return array_map(static fn (SpecialOpeningDayFormData $day): SpecialOpeningDayInput => new SpecialOpeningDayInput($day->localDate, $day->mode, $day->note, array_map(static fn (SpecialOpeningIntervalFormData $interval, int $index): SpecialOpeningIntervalInput => new SpecialOpeningIntervalInput($index + 1, $interval->opensAt, $interval->closesAt, $interval->closesNextDay), $day->intervals, array_keys($day->intervals))), $data->specialOpeningDays);
    }

    /** @return list<ExternalReferenceInput> */
    private static function references(PlaceAdminFormData $data): array
    {
        return array_map(static fn (ExternalReferenceFormData $reference): ExternalReferenceInput => new ExternalReferenceInput($reference->provider, $reference->externalId, $reference->sourceUrl), $data->externalReferences);
    }
}
