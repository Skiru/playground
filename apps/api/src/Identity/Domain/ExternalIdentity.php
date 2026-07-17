<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

final class ExternalIdentity
{
    private Uuid $id;
    private User $user;
    private ExternalIdentityProvider $provider;
    private string $providerSubject;
    private string $providerEmail;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $lastUsedAt;

    public function __construct(
        User $user,
        ExternalIdentityProvider $provider,
        string $providerSubject,
        string $providerEmail,
        \DateTimeImmutable $now,
        ?Uuid $id = null
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->user = $user;
        $this->provider = $provider;
        $this->providerSubject = $providerSubject;
        $this->providerEmail = $providerEmail;
        $this->createdAt = $now;
        $this->lastUsedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserId(): Uuid
    {
        return $this->user->getId();
    }

    public function getProvider(): ExternalIdentityProvider
    {
        return $this->provider;
    }

    public function getProviderSubject(): string
    {
        return $this->providerSubject;
    }

    public function getProviderEmail(): string
    {
        return $this->providerEmail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function recordUse(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }
}
