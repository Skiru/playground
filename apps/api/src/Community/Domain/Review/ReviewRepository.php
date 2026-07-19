<?php

declare(strict_types=1);

namespace App\Community\Domain\Review;

use Symfony\Component\Uid\Uuid;

interface ReviewRepository
{
    public function findById(Uuid $id): ?Review;
    public function findActiveByUserAndPlace(Uuid $userId, Uuid $placeId): ?Review;
    /** @return list<Review> */
    public function findByPlaceId(Uuid $placeId, int $page, int $pageSize, string $sort): array;
    public function countByPlaceId(Uuid $placeId): int;
    public function save(Review $review): void;
    /** @return list<Review> */
    public function findByAuthorId(Uuid $authorId, int $page, int $pageSize): array;
    public function countByAuthorId(Uuid $authorId): int;
}
