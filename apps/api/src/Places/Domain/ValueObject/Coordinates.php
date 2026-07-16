<?php

declare(strict_types=1);

namespace App\Places\Domain\ValueObject;

final readonly class Coordinates
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90 degrees.');
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180 degrees.');
        }
    }

    public function toPostgisWkt(): string
    {
        return \sprintf('POINT(%s %s)', $this->longitude, $this->latitude);
    }

    /** @return array{float, float} */
    public function toMapLibre(): array
    {
        return [$this->longitude, $this->latitude];
    }
}
