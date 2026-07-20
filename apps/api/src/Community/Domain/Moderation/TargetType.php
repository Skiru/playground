<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

enum TargetType: string
{
    case REVIEW = 'REVIEW';
    case PLACE_COMMENT = 'PLACE_COMMENT';
    case FORUM_THREAD = 'FORUM_THREAD';
    case FORUM_POST = 'FORUM_POST';
}
