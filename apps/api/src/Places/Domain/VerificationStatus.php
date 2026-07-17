<?php

declare(strict_types=1);

namespace App\Places\Domain;

enum VerificationStatus: string
{
    case UNVERIFIED = 'unverified';
    case ADMIN_VERIFIED = 'admin_verified';
    case OWNER_DECLARED = 'owner_declared';
    case COMMUNITY_CONFIRMED = 'community_confirmed';
}
