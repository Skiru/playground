<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

final readonly class EmailAddress implements \Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = mb_strtolower(trim($value));
        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
