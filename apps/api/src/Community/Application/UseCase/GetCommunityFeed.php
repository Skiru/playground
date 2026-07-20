<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\CommunityFeedQuery;

final class GetCommunityFeed
{
    public function __construct(
        private readonly CommunityFeedQuery $feedQuery,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{nextCursor: ?string, hasNextPage: bool}}
     */
    public function execute(int $limit, ?string $cursorStr, ?string $typeFilter, ?string $cityIdFilter, ?string $categoryIdFilter): array
    {
        return $this->feedQuery->getFeed($limit, $cursorStr, $typeFilter, $cityIdFilter, $categoryIdFilter);
    }
}
