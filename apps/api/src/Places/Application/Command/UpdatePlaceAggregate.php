<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class UpdatePlaceAggregate
{
    public function __construct(
        public string $placeId,
        public int $expectedVersion,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $description,
        public string $addressLine1,
        public string $postalCode,
        public string $citySlug,
        public string $countryCode,
        public float $latitude,
        public float $longitude,
        public string $timezone,
        public bool $indoor,
        public bool $outdoor,
        public bool $freeEntry,
        public VerificationStatusInput $verificationStatus,
        /** @var list<string> */
        public array $categorySlugs,
        public string $primaryCategorySlug,
        /** @var list<string> */
        public array $amenitySlugs,
        /** @var list<AgeZoneInput> */
        public array $ageZones,
        public OpeningHoursModeInput $openingHoursMode,
        /** @var list<WeeklyOpeningIntervalInput> */
        public array $weeklyOpeningHours,
        /** @var list<SpecialOpeningDayInput> */
        public array $specialOpeningDays,
        /** @var list<ExternalReferenceInput> */
        public array $externalReferences,
        public ?string $addressLine2 = null,
        public ?string $priceDescription = null,
        public ?string $websiteUrl = null,
        public ?string $phone = null,
    ) {
        if ($expectedVersion < 1) {
            throw new \InvalidArgumentException('Expected version must be positive.');
        }
    }
}
