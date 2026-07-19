<?php

declare(strict_types=1);

namespace App\Community\Domain\PlaceDiscussion;

use Symfony\Component\Uid\Uuid;

interface PlaceCommentRepository
{
    public function findById(Uuid $id): ?PlaceComment;
    /** @return list<PlaceComment> */
    public function findByPlaceId(Uuid $placeId, int $page, int $pageSize): array;
    public function countByPlaceId(Uuid $placeId): int;
    public function save(PlaceComment $comment): void;
}
