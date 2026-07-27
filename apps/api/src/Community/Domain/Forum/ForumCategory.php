<?php

declare(strict_types=1);

namespace App\Community\Domain\Forum;

use Symfony\Component\Uid\Uuid;

final class ForumCategory
{
    private Uuid $id;
    private string $slug;
    private string $name;
    private string $description;
    private ?Uuid $cityId;
    private int $displayOrder;
    private bool $active;

    public function __construct(
        Uuid $id,
        string $slug,
        string $name,
        string $description,
        ?Uuid $cityId,
        int $displayOrder,
        bool $active = true,
    ) {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = $name;
        $this->description = $description;
        $this->cityId = $cityId;
        $this->displayOrder = $displayOrder;
        $this->active = $active;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function cityId(): ?Uuid
    {
        return $this->cityId;
    }

    public function displayOrder(): int
    {
        return $this->displayOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
