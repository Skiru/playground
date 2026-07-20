<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\ModerationQueueQuery;

final class ListModerationQueue
{
    public function __construct(
        private readonly ModerationQueueQuery $queueQuery,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $statusFilter, int $page, int $pageSize): array
    {
        return $this->queueQuery->getQueue($statusFilter, $page, $pageSize);
    }
}
