<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final readonly class ProviderPlace
{
    /**
     * @param list<string>               $categories
     * @param array<string, mixed>       $snapshot
     * @param list<ProviderSourceRecord> $provenance
     */
    public function __construct(public string $externalId, public string $release, public ?string $recordVersion, public string $name, public float $latitude, public float $longitude, public ?string $addressLine1, public ?string $postalCode, public ?string $locality, public ?string $countryCode, public ?string $website, public ?string $phone, public array $categories, public ?string $basicCategory, public ?float $confidence, public ?string $operatingStatus, public array $snapshot, public array $provenance = [])
    {
        if ('' === trim($externalId) || '' === trim($name) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidProviderRecord('Provider record lacks identity, name, or valid coordinates.');
        }
    }
}
