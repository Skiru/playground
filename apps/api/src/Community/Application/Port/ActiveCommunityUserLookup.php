<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use Symfony\Component\Uid\Uuid;

interface ActiveCommunityUserLookup
{
    public function isActiveUser(Uuid $userId): bool;
}
