<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use Symfony\Component\Uid\Uuid;

interface PublicAuthorProfileLookup
{
    /** @return array{id: string, displayName: string, initials: string} | null */
    public function getProfile(Uuid $userId): ?array;
}
