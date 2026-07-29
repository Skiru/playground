<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final class DuplicateDetector
{
    public function haversineMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
