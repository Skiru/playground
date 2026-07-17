<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class ExternalReferenceInput
{
    public function __construct(public string $provider, public string $externalId, public ?string $sourceUrl = null)
    {
        if ('' === trim($provider) || '' === trim($externalId)) {
            throw new \InvalidArgumentException('External provider and identifier are required.');
        }
    }
}
