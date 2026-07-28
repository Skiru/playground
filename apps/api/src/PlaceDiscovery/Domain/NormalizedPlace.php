<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

final readonly class NormalizedPlace
{
    /** @param list<string> $categories */
    public function __construct(public string $name, public string $normalizedName, public ?string $websiteHost, public ?string $phone, public array $categories)
    {
    }
}
