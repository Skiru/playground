<?php

declare(strict_types=1);

namespace App\Places\Domain\ValueObject;

final readonly class PlaceName
{
    public function __construct(public string $value)
    {
        if (empty(trim($value))) {
            throw new \InvalidArgumentException('Place name cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
