<?php

declare(strict_types=1);

namespace App\Community\Domain\Review;

enum ReviewStatus: string
{
    case PUBLISHED = 'PUBLISHED';
    case HIDDEN = 'HIDDEN';
    case DELETED_BY_AUTHOR = 'DELETED_BY_AUTHOR';
    case REMOVED_BY_MODERATOR = 'REMOVED_BY_MODERATOR';

    public function isActive(): bool
    {
        return self::PUBLISHED === $this || self::HIDDEN === $this;
    }
}
