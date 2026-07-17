<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case DELETED = 'DELETED';
}
