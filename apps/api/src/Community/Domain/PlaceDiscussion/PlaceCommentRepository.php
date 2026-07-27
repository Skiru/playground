<?php

declare(strict_types=1);

namespace App\Community\Domain\PlaceDiscussion;

use Symfony\Component\Uid\Uuid;

interface PlaceCommentRepository
{
    public function findById(Uuid $id): ?PlaceComment;

    public function save(PlaceComment $comment): void;
}
