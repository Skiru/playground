<?php

declare(strict_types=1);

namespace App\Personalization\Domain;

use App\Identity\Domain\User;
use Symfony\Component\Uid\Uuid;

final class Visit
{
    private Uuid $id;
    private User $user;
    private Uuid $placeId;
    private \DateTimeImmutable $visitedOn;
    private ?string $note = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        Uuid $placeId,
        \DateTimeImmutable $visitedOn,
        ?string $note,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->validate($visitedOn, $note, $now);

        $this->id = $id ?? Uuid::v7();
        $this->user = $user;
        $this->placeId = $placeId;
        $this->visitedOn = $visitedOn->setTime(0, 0, 0);
        $this->note = null !== $note ? trim($note) : null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getVisitedOn(): \DateTimeImmutable
    {
        return $this->visitedOn;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(\DateTimeImmutable $visitedOn, ?string $note, \DateTimeImmutable $now): void
    {
        $this->validate($visitedOn, $note, $now);

        $this->visitedOn = $visitedOn->setTime(0, 0, 0);
        $this->note = null !== $note ? trim($note) : null;
        $this->updatedAt = $now;
    }

    private function validate(\DateTimeImmutable $visitedOn, ?string $note, \DateTimeImmutable $now): void
    {
        if ($visitedOn->setTime(0, 0, 0) > $now->setTime(0, 0, 0)) {
            throw new \InvalidArgumentException('Visited date cannot be in the future.');
        }

        if (null !== $note) {
            $trimmed = trim($note);
            if (mb_strlen($trimmed) > 1000) {
                throw new \InvalidArgumentException('Visit note cannot exceed 1000 characters.');
            }
        }
    }
}
