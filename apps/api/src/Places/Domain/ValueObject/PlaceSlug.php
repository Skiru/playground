<?php

declare(strict_types=1);

namespace App\Places\Domain\ValueObject;

final readonly class PlaceSlug
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new \InvalidArgumentException('Invalid slug format.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
