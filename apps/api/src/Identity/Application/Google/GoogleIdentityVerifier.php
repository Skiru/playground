<?php

declare(strict_types=1);

namespace App\Identity\Application\Google;

use App\Identity\Domain\Google\VerifiedGoogleIdentity;

interface GoogleIdentityVerifier
{
    /**
     * @throws \InvalidArgumentException if token signature/claims are invalid
     */
    public function verify(string $idToken): VerifiedGoogleIdentity;
}
