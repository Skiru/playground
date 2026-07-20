<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

interface CommunityFeedQuery
{
    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{nextCursor: ?string, hasNextPage: bool}}
     */
    public function getFeed(int $limit, ?string $cursorStr, ?string $typeFilter, ?string $cityIdFilter, ?string $categoryIdFilter): array;
}
