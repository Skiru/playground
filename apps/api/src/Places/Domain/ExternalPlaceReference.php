<?php

declare(strict_types=1);

namespace App\Places\Domain;

use Symfony\Component\Uid\Uuid;

final class ExternalPlaceReference
{
    private Uuid $id;

    public function __construct(
        private Place $place,
        private string $provider,
        private string $externalId,
        private ?string $sourceUrl = null,
        private ?\DateTimeImmutable $importedAt = null,
        private ?\DateTimeImmutable $lastVerifiedAt = null,
    ) {
        if ('' === trim($provider) || '' === trim($externalId)) {
            throw new \InvalidArgumentException('External provider and identifier are required.');
        }
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function place(): Place
    {
        return $this->place;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function importedAt(): ?\DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function lastVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastVerifiedAt;
    }
}
