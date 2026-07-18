<?php

declare(strict_types=1);

namespace App\Identity\UI\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (method_exists($user, 'status')) {
            $status = $user->status();
            $statusStr = $status instanceof \BackedEnum ? $status->value : (string) $status;
            if ('ACTIVE' !== $statusStr) {
                throw new DisabledException('User account is not active.');
            }
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (method_exists($user, 'status')) {
            $status = $user->status();
            $statusStr = $status instanceof \BackedEnum ? $status->value : (string) $status;
            if ('ACTIVE' !== $statusStr) {
                throw new DisabledException('User account is not active.');
            }
        }
    }
}
