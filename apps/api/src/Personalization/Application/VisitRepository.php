<?php

declare(strict_types=1);

namespace App\Personalization\Application;

use App\Personalization\Domain\Visit;
use Symfony\Component\Uid\Uuid;

interface VisitRepository
{
    public function findByIdAndUser(Uuid $id, Uuid $userId): ?Visit;
    /**
     * @return list<Visit>
     */
    public function findByUserId(Uuid $userId, int $page = 1, int $pageSize = 20): array;
    public function countByUserId(Uuid $userId): int;
    public function findLastVisitedOnByPlaces(Uuid $userId, array $placeIds): array;
    public function save(Visit $visit): void;
    public function remove(Visit $visit): void;
}
