<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

use Symfony\Component\Uid\Uuid;

interface ModerationActionRepository
{
    public function findById(Uuid $id): ?ModerationActionRecord;

    /** @return list<ModerationActionRecord> */
    public function findByTarget(Uuid $targetId, TargetType $targetType): array;

    public function save(ModerationActionRecord $record): void;
}
