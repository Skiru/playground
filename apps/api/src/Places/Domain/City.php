<?php

declare(strict_types=1);

namespace App\Places\Domain;

use App\Places\Domain\ValueObject\Coordinates;
use Symfony\Component\Uid\Uuid;

final class City
{
    private Uuid $id;
    private \DateTimeImmutable $updatedAt;
    private float $latitude;
    private float $longitude;

    public function __construct(
        private string $name,
        private string $slug,
        private string $countryCode,
        private Coordinates $center,
        private int $defaultZoom,
        private int $defaultRadiusKm,
        private string $timezone,
        private bool $enabled,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->latitude = $center->latitude;
        $this->longitude = $center->longitude;
        $this->updatedAt = $createdAt;
        if (2 !== \strlen($countryCode) || $defaultRadiusKm < 1 || false === timezone_open($timezone)) {
            throw new \InvalidArgumentException('Invalid city configuration.');
        }
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public static function reconstitute(
        Uuid $id,
        string $name,
        string $slug,
        string $countryCode,
        Coordinates $center,
        int $defaultZoom,
        int $defaultRadiusKm,
        string $timezone,
        bool $enabled,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ): self {
        $city = new self($name, $slug, $countryCode, $center, $defaultZoom, $defaultRadiusKm, $timezone, $enabled, $createdAt);
        $city->id = $id;
        $city->updatedAt = $updatedAt;

        return $city;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function coordinates(): Coordinates
    {
        return new Coordinates($this->latitude, $this->longitude);
    }

    public function defaultZoom(): int
    {
        return $this->defaultZoom;
    }

    public function defaultRadiusKm(): int
    {
        return $this->defaultRadiusKm;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
