<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Google;

use App\Identity\Application\Google\GoogleIdentityVerifier;
use App\Identity\Domain\Google\VerifiedGoogleIdentity;

final class FakeGoogleIdentityVerifier implements GoogleIdentityVerifier
{
    public function verify(string $idToken): VerifiedGoogleIdentity
    {
        if (str_starts_with($idToken, 'invalid') || '' === trim($idToken)) {
            throw new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Invalid Google ID token.');
        }

        // Support a format like fake_google_token_{sub}_{email}_{displayName}
        $parts = explode('_', $idToken);

        $subject = $parts[3] ?? 'fake-google-subject-123';
        $email = $parts[4] ?? 'user@example.com';
        $displayName = $parts[5] ?? 'Test Google User';

        // URL decode displayName if it contains spaces encoded as %20
        $displayName = rawurldecode($displayName);

        return new VerifiedGoogleIdentity(
            subject: $subject,
            email: $email,
            emailVerified: true,
            displayName: $displayName,
            pictureUrl: null,
            issuedAt: time(),
            expiresAt: time() + 3600
        );
    }
}
