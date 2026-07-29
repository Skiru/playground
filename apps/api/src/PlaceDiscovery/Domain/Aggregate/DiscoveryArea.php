<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain\Aggregate;

final readonly class DiscoveryArea
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $enabled,
        public string $countryCode,
        public float $centerLatitude,
        public float $centerLongitude,
        public float $radiusKm,
        public float $minimumConfidence,
        public int $maximumCandidatesPerRun,
        public string $profile = 'family-v1',
        public int $version = 1,
    ) {
        if ('' === trim($name) || !preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new \DomainException('A discovery area requires a name and ISO alpha-2 country code.');
        }
        if ($centerLatitude < -90 || $centerLatitude > 90 || $centerLongitude < -180 || $centerLongitude > 180) {
            throw new \DomainException('Discovery area coordinates are outside valid ranges.');
        }
        if ($radiusKm < 0.1 || $radiusKm > 25 || $maximumCandidatesPerRun < 1 || $maximumCandidatesPerRun > 1000 || $minimumConfidence < 0 || $minimumConfidence > 1) {
            throw new \DomainException('Discovery area exceeds safe resource limits.');
        }
    }

    /** @return array{float,float,float,float} west,south,east,north */
    public function boundingBox(): array
    {
        $latDelta = $this->radiusKm / 111.32;
        $cosine = max(0.01, cos(deg2rad($this->centerLatitude)));
        $lonDelta = $this->radiusKm / (111.32 * $cosine);

        return [max(-180, $this->centerLongitude - $lonDelta), max(-90, $this->centerLatitude - $latDelta), min(180, $this->centerLongitude + $lonDelta), min(90, $this->centerLatitude + $latDelta)];
    }
}
