<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

interface ForumCategoryRepository
{
    public function findById(Uuid $id): ?ForumCategory;

    public function findBySlug(string $slug): ?ForumCategory;

    /** @return list<ForumCategory> */
    public function findAllActive(): array;

    /** @return list<ForumCategory> */
    public function findAll(): array;

    public function save(ForumCategory $category): void;
}
