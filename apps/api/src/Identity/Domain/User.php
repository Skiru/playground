<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\EmailAddress;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    private Uuid $id;
    private string $email;
    private ?string $passwordHash = null;
    private ?string $googleSubject = null;
    /** @var list<string> */
    private array $roles;
    private UserStatus $status = UserStatus::ACTIVE;
    private \DateTimeImmutable $updatedAt;
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** @param list<string> $roles */
    public function __construct(
        EmailAddress $email,
        private string $displayName,
        private readonly \DateTimeImmutable $createdAt,
        array $roles = ['ROLE_USER'],
    ) {
        if ('' === trim($displayName)) {
            throw new \InvalidArgumentException('Display name is required.');
        }

        $this->id = Uuid::v7();
        $this->email = $email->value;
        $this->roles = array_values(array_unique($roles));
        $this->updatedAt = $createdAt;
    }

    public static function administrator(EmailAddress $email, string $displayName, \DateTimeImmutable $now): self
    {
        return new self($email, $displayName, $now, ['ROLE_ADMIN']);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function email(): EmailAddress
    {
        return new EmailAddress($this->email);
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Persisted email cannot be empty.');
        }

        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function googleSubject(): ?string
    {
        return $this->googleSubject;
    }

    public function eraseCredentials(): void
    {
    }

    public function setPasswordHash(string $hash, \DateTimeImmutable $now): void
    {
        if ('' === $hash) {
            throw new \InvalidArgumentException('Password hash cannot be empty.');
        }
        $this->passwordHash = $hash;
        $this->updatedAt = $now;
    }

    public function recordLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
        $this->updatedAt = $now;
    }

    public function linkGoogleSubject(string $subject, \DateTimeImmutable $now): void
    {
        if ('' === trim($subject)) {
            throw new \InvalidArgumentException('Google subject cannot be empty.');
        }
        $this->googleSubject = $subject;
        $this->updatedAt = $now;
    }
}
