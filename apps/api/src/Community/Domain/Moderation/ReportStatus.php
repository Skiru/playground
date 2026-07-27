<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

enum ReportStatus: string
{
    case OPEN = 'OPEN';
    case IN_REVIEW = 'IN_REVIEW';
    case RESOLVED = 'RESOLVED';
    case DISMISSED = 'DISMISSED';
}
