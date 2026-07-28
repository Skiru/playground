<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final readonly class ProviderSourceRecord implements \JsonSerializable
{
    public function __construct(public string $property, public string $dataset, public ?string $license = null, public ?string $recordId = null, public ?string $updateTime = null, public ?string $provider = null, public ?string $resource = null, public ?string $version = null, public ?float $confidence = null)
    {
        foreach ([$property, $dataset, $license, $recordId, $updateTime, $provider, $resource, $version] as $value) {
            if (null !== $value && \strlen($value) > 255) {
                throw new InvalidProviderRecord('Provider provenance value exceeds 255 bytes.');
            }
        }
        if ('' === trim($dataset)) {
            throw new InvalidProviderRecord('Provider provenance requires a dataset.');
        }
        if (null !== $license && '' === trim($license)) {
            throw new InvalidProviderRecord('Provider provenance license must be null or non-empty.');
        }
        if (null !== $confidence && ($confidence < 0 || $confidence > 1)) {
            throw new InvalidProviderRecord('Provider provenance confidence is outside its bounded range.');
        }
    }

    /** @return array<string, float|string|null> */
    public function jsonSerialize(): array
    {
        return ['property' => $this->property, 'dataset' => $this->dataset, 'license' => $this->license, 'record_id' => $this->recordId, 'update_time' => $this->updateTime, 'provider' => $this->provider, 'resource' => $this->resource, 'version' => $this->version, 'confidence' => $this->confidence];
    }
}
