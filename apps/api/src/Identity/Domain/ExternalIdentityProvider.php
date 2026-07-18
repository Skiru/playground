<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum ExternalIdentityProvider: string
{
    case GOOGLE = 'GOOGLE';
}
