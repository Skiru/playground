<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ExternalIdentity;
use App\Identity\Domain\ExternalIdentityProvider;

interface ExternalIdentityRepository
{
    public function findByProviderAndSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity;

    public function save(ExternalIdentity $identity): void;
}
