<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Forum;

use App\Community\Domain\Forum\ForumCategory;
use App\Community\Domain\Forum\ForumCategoryRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalForumCategoryRepository implements ForumCategoryRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(Uuid $id): ?ForumCategory
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM forum_categories WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findBySlug(string $slug): ?ForumCategory
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM forum_categories WHERE slug = :slug',
            ['slug' => $slug]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    /**
     * @return list<ForumCategory>
     */
    public function findAllActive(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM forum_categories WHERE active = true ORDER BY display_order ASC'
        );

        return array_map([$this, 'reconstitute'], $rows);
    }

    /**
     * @return list<ForumCategory>
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM forum_categories ORDER BY display_order ASC'
        );

        return array_map([$this, 'reconstitute'], $rows);
    }

    public function save(ForumCategory $category): void
    {
        $id = $category->id()->toRfc4122();
        $slug = $category->slug();
        $name = $category->name();
        $description = $category->description();
        $cityId = $category->cityId()?->toRfc4122();
        $displayOrder = $category->displayOrder();
        $active = $category->isActive();

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM forum_categories WHERE id = :id',
            ['id' => $id]
        );

        if ($exists) {
            $this->connection->executeStatement(
                'UPDATE forum_categories SET 
                    slug = :slug,
                    name = :name,
                    description = :description,
                    city_id = :city_id,
                    display_order = :display_order,
                    active = :active
                 WHERE id = :id',
                [
                    'id' => $id,
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'city_id' => $cityId,
                    'display_order' => $displayOrder,
                    'active' => $active ? 1 : 0,
                ]
            );
        } else {
            $this->connection->executeStatement(
                'INSERT INTO forum_categories (id, slug, name, description, city_id, display_order, active) 
                 VALUES (:id, :slug, :name, :description, :city_id, :display_order, :active)',
                [
                    'id' => $id,
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'city_id' => $cityId,
                    'display_order' => $displayOrder,
                    'active' => $active ? 1 : 0,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reconstitute(array $row): ForumCategory
    {
        return new ForumCategory(
            Uuid::fromString((string) $row['id']),
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['description'],
            null === $row['city_id'] ? null : Uuid::fromString((string) $row['city_id']),
            (int) $row['display_order'],
            (bool) $row['active']
        );
    }
}
