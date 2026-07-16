<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class Amenity
{
    private Uuid $id;

    public function __construct(
        private string $name,
        private string $slug,
        private string $group,
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

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function group(): string
    {
        return $this->group;
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
