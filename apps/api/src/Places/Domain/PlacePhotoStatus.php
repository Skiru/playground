<?php

declare(strict_types=1);

namespace App\Places\Domain;

enum PlacePhotoStatus: string
{
    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case DELETING = 'DELETING';
}
