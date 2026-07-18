<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage;

final readonly class StorageObjectKey
{
    private string $value;

    public function __construct(string $value)
    {
        if (str_contains($value, '..') || str_contains($value, '\\') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid storage path.');
        }

        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ('' === $segment) {
                throw new \InvalidArgumentException('Empty segments not allowed.');
            }
        }

        $uuidRegex = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        $sourcePattern = '#^places/('.$uuidRegex.')/photos/('.$uuidRegex.')/source$#';
        $variantPattern = '#^places/('.$uuidRegex.')/photos/('.$uuidRegex.')/variants/([0-9]+)/([a-zA-Z0-9_-]+)\.webp$#';

        if (!preg_match($sourcePattern, $value) && !preg_match($variantPattern, $value)) {
            throw new \InvalidArgumentException('Invalid storage key format.');
        }

        $this->value = $value;
    }

    public static function source(string $placeId, string $photoId): self
    {
        return new self(\sprintf('places/%s/photos/%s/source', $placeId, $photoId));
    }

    public static function variant(string $placeId, string $photoId, int $generation, string $variant): self
    {
        return new self(\sprintf('places/%s/photos/%s/variants/%d/%s.webp', $placeId, $photoId, $generation, $variant));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
