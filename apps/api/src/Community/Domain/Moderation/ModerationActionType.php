<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

enum ModerationActionType: string
{
    case HIDE = 'HIDE';
    case REMOVE = 'REMOVE';
    case RESTORE = 'RESTORE';
    case LOCK = 'LOCK';
    case UNLOCK = 'UNLOCK';
    case PIN = 'PIN';
    case UNPIN = 'UNPIN';
    case DISMISS_REPORT = 'DISMISS_REPORT';
    case RESOLVE_REPORT = 'RESOLVE_REPORT';
}
