<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumCategoryRepository;

final class ListForumCategories
{
    public function __construct(private readonly ForumCategoryRepository $categoryRepository)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $categories = $this->categoryRepository->findAllActive();

        return array_map(static fn ($c) => [
            'id' => $c->id()->toString(),
            'slug' => $c->slug(),
            'name' => $c->name(),
            'description' => $c->description(),
            'cityId' => $c->cityId()?->toString(),
            'displayOrder' => $c->displayOrder(),
            'active' => $c->isActive(),
        ], $categories);
    }
}
