<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Domain\Place;
use App\Shared\Application\Clock;

final readonly class UnpublishPlace
{
    public function __construct(private PlaceRepository $places, private Clock $clock)
    {
    }

    public function execute(Place $place): void
    {
        $place->unpublish($this->clock->now());
        $this->places->save($place);
    }
}
