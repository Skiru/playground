<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Domain\PlaceStatus;

final readonly class AdminPlaceSummary
{
    public function __construct(public string $id, public string $name, public string $slug, public PlaceStatus $status, public string $city, public int $version)
    {
    }
}
