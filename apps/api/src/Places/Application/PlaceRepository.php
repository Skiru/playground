<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Domain\Amenity;
use App\Places\Domain\Category;
use App\Places\Domain\City;
use App\Places\Domain\Place;

interface PlaceRepository
{
    /** @return list<AdminPlaceSummary> */
    public function listForAdministration(): array;

    public function get(string $id): Place;

    public function getForUpdate(string $id): Place;

    public function cityBySlug(string $slug): City;

    public function categoryBySlug(string $slug): Category;

    /**
     * @param list<string> $slugs
     *
     * @return list<Category>
     */
    public function categoriesBySlugs(array $slugs): array;

    /**
     * @param list<string> $slugs
     *
     * @return list<Amenity>
     */
    public function amenitiesBySlugs(array $slugs): array;

    public function add(Place $place): void;

    public function save(Place $place, int $expectedVersion): void;
}
