<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

final readonly class PlaceListItem implements \JsonSerializable
{
    /**
     * @param list<array{slug: string, name: string}> $categories
     * @param list<array{slug: string, name: string}> $amenities
     * @param array<string, string>|null              $mainPhoto
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $shortDescription,
        public string $city,
        public array $categories,
        public AgeSummary $age,
        public bool $indoor,
        public bool $outdoor,
        public bool $freeEntry,
        public string $verificationStatus,
        public array $amenities,
        public ?float $distanceMeters,
        public float $longitude,
        public float $latitude,
        public OpeningStatus $opening,
        public bool $complete,
        public float $relevanceScore,
        public float $averageRating = 0.0,
        public int $totalReviews = 0,
        public ?array $mainPhoto = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'slug' => $this->slug, 'name' => $this->name, 'short_description' => $this->shortDescription, 'city' => $this->city, 'categories' => $this->categories, ...$this->age->jsonSerialize(), 'indoor' => $this->indoor, 'outdoor' => $this->outdoor, 'free_entry' => $this->freeEntry, 'verification_status' => $this->verificationStatus, 'amenities' => $this->amenities, 'distance_meters' => $this->distanceMeters, 'longitude' => $this->longitude, 'latitude' => $this->latitude, 'is_open_now' => $this->opening->jsonSerialize(), 'complete' => $this->complete, 'relevance_score' => $this->relevanceScore, 'average_rating' => $this->averageRating, 'total_reviews' => $this->totalReviews, 'main_photo' => $this->mainPhoto];
    }
}
