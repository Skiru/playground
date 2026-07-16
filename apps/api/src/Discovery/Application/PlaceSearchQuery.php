<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use Symfony\Component\HttpFoundation\Request;

final readonly class PlaceSearchQuery
{
    /** @param list<string> $amenities */
    public function __construct(
        public ?string $city,
        public ?string $category,
        public ?int $ageMonths,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $radiusKm,
        public array $amenities,
        public ?bool $indoor,
        public ?bool $outdoor,
        public ?bool $freeEntry,
        public bool $openNow,
        public ?string $q,
        public int $page,
        public int $pageSize,
        public string $sort,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $age = self::integer($request, 'ageMonths');
        $lat = self::float($request, 'latitude');
        $lon = self::float($request, 'longitude');
        $radius = self::float($request, 'radiusKm');
        $page = self::integer($request, 'page') ?? 1;
        $pageSize = self::integer($request, 'pageSize') ?? 20;
        $q = self::text($request, 'q');
        $sort = self::text($request, 'sort') ?? 'relevance';
        $amenities = array_values(array_unique(array_filter(array_map('strval', $request->query->all('amenities')))));

        if (null !== $age && ($age < 0 || $age > 216)) {
            throw new \InvalidArgumentException('ageMonths must be between 0 and 216.');
        }
        if (null !== $lat && ($lat < -90 || $lat > 90)) {
            throw new \InvalidArgumentException('latitude must be between -90 and 90.');
        }
        if (null !== $lon && ($lon < -180 || $lon > 180)) {
            throw new \InvalidArgumentException('longitude must be between -180 and 180.');
        }
        if ((null === $lat) !== (null === $lon)) {
            throw new \InvalidArgumentException('latitude and longitude must be provided together.');
        }
        if (null !== $radius && ($radius < 1 || $radius > 100)) {
            throw new \InvalidArgumentException('radiusKm must be between 1 and 100.');
        }
        if (null !== $radius && null === $lat) {
            throw new \InvalidArgumentException('radiusKm requires an origin.');
        }
        if (null !== $q && mb_strlen($q) > 100) {
            throw new \InvalidArgumentException('q cannot exceed 100 characters.');
        }
        if ($page < 1 || $pageSize < 1 || $pageSize > 50) {
            throw new \InvalidArgumentException('Invalid pagination.');
        }
        if (\count($amenities) > 20) {
            throw new \InvalidArgumentException('At most 20 amenities are allowed.');
        }
        if (!\in_array($sort, ['relevance', 'distance', 'name', 'recentlyVerified'], true)) {
            throw new \InvalidArgumentException('Unsupported sort value.');
        }
        if ('distance' === $sort && null === $lat) {
            throw new \InvalidArgumentException('Distance sorting requires an origin.');
        }

        return new self(self::text($request, 'city'), self::text($request, 'category'), $age, $lat, $lon, $radius, $amenities, self::boolean($request, 'indoor'), self::boolean($request, 'outdoor'), self::boolean($request, 'freeEntry'), self::boolean($request, 'openNow') ?? false, $q, $page, $pageSize, $sort);
    }

    private static function text(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            return null;
        }

        return trim($value);
    }

    private static function integer(Request $request, string $name): ?int
    {
        $value = self::text($request, $name);
        if (null === $value) {
            return null;
        }
        $parsed = filter_var($value, \FILTER_VALIDATE_INT);
        if (false === $parsed) {
            throw new \InvalidArgumentException($name.' must be an integer.');
        }

        return $parsed;
    }

    private static function float(Request $request, string $name): ?float
    {
        $value = self::text($request, $name);
        if (null === $value || !is_numeric($value)) {
            return null === $value ? null : throw new \InvalidArgumentException($name.' must be numeric.');
        }

        return (float) $value;
    }

    private static function boolean(Request $request, string $name): ?bool
    {
        $value = self::text($request, $name);
        if (null === $value) {
            return null;
        }
        $parsed = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (null === $parsed) {
            throw new \InvalidArgumentException($name.' must be true or false.');
        }

        return $parsed;
    }
}
