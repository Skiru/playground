<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Domain\Amenity;
use App\Places\Domain\Category;
use App\Places\Domain\City;
use App\Places\Domain\Place;

interface PlaceRepository
{
    /** @return array{items: list<AdminPlaceSummary>, total: int} */
    public function listForAdministration(
        ?string $search = null,
        ?string $status = null,
        ?string $city = null,
        ?string $sort = null,
        int $page = 1,
        int $pageSize = 20,
    ): array;

    public function get(string $id): Place;

    public function getForUpdate(string $id): Place;

    public function cityBySlug(string $slug): City;

    /** @return list<City> */
    public function allCities(): array;

    public function categoryBySlug(string $slug): Category;

    /** @return list<Category> */
    public function allCategories(): array;

    /** @return list<Amenity> */
    public function allAmenities(): array;

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
