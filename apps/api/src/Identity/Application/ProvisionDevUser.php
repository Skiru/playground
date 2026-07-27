<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Exception\AccountInactiveException;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\Identity\Domain\ValueObject\EmailAddress;
use App\Shared\Application\Clock;

final readonly class ProvisionDevUser
{
    public function __construct(
        private UserRepository $userRepository,
        private Clock $clock,
    ) {
    }

    /** @param list<string> $roles */
    public function provision(string $email, string $displayName, array $roles, string $status): User
    {
        $emailAddress = new EmailAddress($email);
        $user = $this->userRepository->findByEmail($emailAddress);

        if (null !== $user && UserStatus::ACTIVE !== $user->status()) {
            throw new AccountInactiveException();
        }

        if (null !== $user) {
            return $user;
        }

        $userStatus = UserStatus::tryFrom($status) ?? UserStatus::ACTIVE;
        $user = new User($emailAddress, $displayName, $this->clock->now(), $roles, $userStatus);
        $this->userRepository->save($user);

        return $user;
    }
}
