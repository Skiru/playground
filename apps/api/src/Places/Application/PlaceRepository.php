<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Domain\Place;

interface PlaceRepository
{
    public function save(Place $place): void;
}
