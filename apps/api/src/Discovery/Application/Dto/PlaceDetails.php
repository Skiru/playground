<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

final readonly class PlaceDetails implements \JsonSerializable
{
    /**
     * @param list<array{slug: string, name: string}>                                                          $categories
     * @param list<array{slug: string, name: string}>                                                          $amenities
     * @param list<array{name: string, minAgeMonths: int, maxAgeMonths: ?int, notes: ?string}>                 $ageZones
     * @param list<array{weekday: int, sequence: int, opensAt: string, closesAt: string, closesNextDay: bool}> $weeklyOpening
     * @param list<array{localDate: string, closed: bool, note: ?string}>                                      $specialOpening
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $shortDescription,
        public string $description,
        public string $cityName,
        public string $citySlug,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $postalCode,
        public string $countryCode,
        public array $categories,
        public array $amenities,
        public array $ageZones,
        public array $weeklyOpening,
        public array $specialOpening,
        public bool $indoor,
        public bool $outdoor,
        public bool $freeEntry,
        public ?string $priceDescription,
        public ?string $websiteUrl,
        public ?string $phone,
        public string $verificationStatus,
        public float $longitude,
        public float $latitude,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'slug' => $this->slug, 'name' => $this->name, 'short_description' => $this->shortDescription, 'description' => $this->description, 'city_name' => $this->cityName, 'city_slug' => $this->citySlug, 'address_line1' => $this->addressLine1, 'address_line2' => $this->addressLine2, 'postal_code' => $this->postalCode, 'country_code' => $this->countryCode, 'categories' => $this->categories, 'amenities' => $this->amenities, 'age_zones' => $this->ageZones, 'weekly_opening' => $this->weeklyOpening, 'special_opening' => $this->specialOpening, 'indoor' => $this->indoor, 'outdoor' => $this->outdoor, 'free_entry' => $this->freeEntry, 'price_description' => $this->priceDescription, 'website_url' => $this->websiteUrl, 'phone' => $this->phone, 'verification_status' => $this->verificationStatus, 'longitude' => $this->longitude, 'latitude' => $this->latitude];
    }
}
