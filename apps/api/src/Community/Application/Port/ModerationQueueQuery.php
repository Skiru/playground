<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

interface ModerationQueueQuery
{
    /**
     * @return array<string, mixed>
     */
    public function getQueue(?string $statusFilter, int $page, int $pageSize): array;
}
