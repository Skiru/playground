<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class CreatePlaceDraft
{
    public function __construct(
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
        public string $categorySlug,
        public bool $indoor,
        public bool $outdoor,
        public bool $freeEntry,
        public ?string $addressLine2 = null,
        public ?string $priceDescription = null,
        public ?string $websiteUrl = null,
        public ?string $phone = null,
    ) {
        if ('' === trim($name) || '' === trim($slug) || '' === trim($citySlug) || '' === trim($categorySlug)) {
            throw new \InvalidArgumentException('Name, slug, city, and category are required.');
        }
    }
}
