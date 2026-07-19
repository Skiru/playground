<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Google;

use App\Identity\Application\Google\GoogleIdentityVerifier;
use App\Identity\Domain\Google\VerifiedGoogleIdentity;
use Google\Client;

final class GoogleApiClientIdentityVerifier implements GoogleIdentityVerifier
{
    private string $clientId;

    public function __construct(string $clientId)
    {
        $this->clientId = $clientId;
    }

    public function verify(string $idToken): VerifiedGoogleIdentity
    {
        if ('' === trim($this->clientId)) {
            throw new \App\Identity\Application\Exception\GoogleConfigurationException('Google client ID cannot be empty.');
        }

        if ('' === trim($idToken)) {
            throw new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Google ID token cannot be empty.');
        }

        // We instantiate Google Client with our Client ID
        $client = new Client(['client_id' => $this->clientId]);

        try {
            $payload = $client->verifyIdToken($idToken);
        } catch (\Throwable $e) {
            throw new \App\Identity\Application\Exception\GoogleProviderUnavailableException('Google token verification failed', 0, $e);
        }

        if (false === $payload) {
            throw new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Invalid Google ID token.');
        }

        $subject = $payload['sub'] ?? '';
        if ('' === $subject) {
            throw new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Google ID token is missing subject (sub) claim.');
        }

        $email = $payload['email'] ?? '';
        if ('' === $email) {
            throw new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Google ID token is missing email claim.');
        }

        $emailVerified = $payload['email_verified'] ?? false;
        if (true !== $emailVerified && 'true' !== $emailVerified) {
            throw new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Google account email is not verified.');
        }

        $displayName = $payload['name'] ?? $email;
        $pictureUrl = $payload['picture'] ?? null;
        $issuedAt = (int) ($payload['iat'] ?? 0);
        $expiresAt = (int) ($payload['exp'] ?? 0);

        return new VerifiedGoogleIdentity(
            subject: $subject,
            email: $email,
            emailVerified: true,
            displayName: $displayName,
            pictureUrl: $pictureUrl,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt
        );
    }
}
