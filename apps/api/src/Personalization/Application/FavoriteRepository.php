<?php

declare(strict_types=1);

namespace App\Personalization\Application;

use App\Personalization\Domain\Favorite;
use Symfony\Component\Uid\Uuid;

interface FavoriteRepository
{
    public function findByUserAndPlace(Uuid $userId, Uuid $placeId): ?Favorite;

    /**
     * @return list<Favorite>
     */
    public function findByUserId(Uuid $userId, int $page = 1, int $pageSize = 20): array;

    public function countByUserId(Uuid $userId): int;

    public function save(Favorite $favorite): void;

    public function remove(Favorite $favorite): void;
}
