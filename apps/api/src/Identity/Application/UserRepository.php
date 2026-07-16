<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailAddress;

interface UserRepository
{
    public function findByEmail(EmailAddress $email): ?User;

    public function save(User $user): void;
}
