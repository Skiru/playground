<?php

declare(strict_types=1);

namespace App\Personalization\Domain;

use App\Identity\Domain\User;
use Symfony\Component\Uid\Uuid;

final class Favorite
{
    private Uuid $id;
    private User $user;
    private Uuid $placeId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        Uuid $placeId,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->user = $user;
        $this->placeId = $placeId;
        $this->createdAt = $now;
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

    public function getPlaceId(): Uuid
    {
        return $this->placeId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
