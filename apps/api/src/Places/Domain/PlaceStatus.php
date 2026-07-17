<?php

declare(strict_types=1);

namespace App\Places\Domain;

enum PlaceStatus: string
{
    case DRAFT = 'draft';
    case PENDING_REVIEW = 'pending_review';
    case PUBLISHED = 'published';
    case NEEDS_REVERIFICATION = 'needs_reverification';
    case TEMPORARILY_CLOSED = 'temporarily_closed';
    case PERMANENTLY_CLOSED = 'permanently_closed';
    case ARCHIVED = 'archived';
}
