<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class Category
{
    private Uuid $id;

    public function __construct(
        private string $name,
        private string $slug,
        private ?string $description,
        private string $iconKey,
        private bool $enabled,
        private int $displayOrder,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public static function reconstitute(Uuid $id, string $name, string $slug, ?string $description, string $iconKey, bool $enabled, int $displayOrder): self
    {
        $category = new self($name, $slug, $description, $iconKey, $enabled, $displayOrder);
        $category->id = $id;

        return $category;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function iconKey(): string
    {
        return $this->iconKey;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function displayOrder(): int
    {
        return $this->displayOrder;
    }
}
