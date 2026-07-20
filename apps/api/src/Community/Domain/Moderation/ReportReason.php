<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

enum ReportReason: string
{
    case SPAM = 'SPAM';
    case HARASSMENT = 'HARASSMENT';
    case INAPPROPRIATE = 'INAPPROPRIATE';
    case MISINFORMATION = 'MISINFORMATION';
    case PRIVACY_CONCERN = 'PRIVACY_CONCERN';
    case OTHER = 'OTHER';
}
