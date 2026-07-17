<?php

declare(strict_types=1);

namespace App\Identity\Domain\Google;

final class VerifiedGoogleIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $displayName,
        public readonly ?string $pictureUrl,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {
    }
}
