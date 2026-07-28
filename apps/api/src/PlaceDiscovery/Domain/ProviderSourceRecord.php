<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final readonly class ProviderSourceRecord implements \JsonSerializable
{
    public function __construct(public string $propertyPath, public string $dataset, public string $license, public ?string $recordId = null, public ?string $updatedAt = null)
    {
        foreach ([$propertyPath, $dataset, $license, $recordId, $updatedAt] as $value) {
            if (null !== $value && \strlen($value) > 255) {
                throw new InvalidProviderRecord('Provider provenance value exceeds 255 bytes.');
            }
        }
        if ('' === trim($propertyPath) || '' === trim($dataset) || '' === trim($license)) {
            throw new InvalidProviderRecord('Provider provenance requires property path, dataset, and license.');
        }
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return ['property_path' => $this->propertyPath, 'dataset' => $this->dataset, 'license' => $this->license, 'record_id' => $this->recordId, 'updated_at' => $this->updatedAt];
    }
}
