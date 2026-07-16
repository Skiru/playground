<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class ReplacePlaceCategories
{
    public function __construct(
        public string $placeId,
        public int $expectedVersion,
        /** @var list<string> */
        public array $categorySlugs,
        public string $primaryCategorySlug,
    ) {
        if ($expectedVersion < 1 || [] === $categorySlugs || !\in_array($primaryCategorySlug, $categorySlugs, true)) {
            throw new \InvalidArgumentException('Categories must include the primary category.');
        }
    }
}
