<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailAddress;
use App\Shared\Application\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CreateAdmin
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private Clock $clock,
    ) {
    }

    public function execute(string $email, string $password, string $displayName): User
    {
        $address = new EmailAddress($email);
        if (null !== $this->users->findByEmail($address)) {
            throw new \DomainException('A user with this email already exists.');
        }
        if (mb_strlen($password) < 12) {
            throw new \InvalidArgumentException('Password must contain at least 12 characters.');
        }

        $now = $this->clock->now();
        $user = User::administrator($address, $displayName, $now);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password), $now);
        $this->users->save($user);

        return $user;
    }
}
