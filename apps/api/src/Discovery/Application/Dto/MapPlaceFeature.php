<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

final readonly class MapPlaceFeature implements \JsonSerializable
{
    /**
     * @param array{type: string, coordinates: array{0: float, 1: float}}                     $geometry
     * @param array{slug: string, name: string, indoor: bool, outdoor: bool, freeEntry: bool} $properties
     */
    public function __construct(
        public string $type,
        public string $id,
        public array $geometry,
        public array $properties,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'geometry' => $this->geometry, 'properties' => $this->properties];
    }
}
