<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Google;

use App\Identity\Application\Exception\GoogleConfigurationException;
use App\Identity\Application\Exception\GoogleCredentialMissingException;
use App\Identity\Application\Exception\GoogleProviderUnavailableException;
use App\Identity\Application\Exception\GoogleTokenAudienceInvalidException;
use App\Identity\Application\Exception\GoogleTokenEmailUnverifiedException;
use App\Identity\Application\Exception\GoogleTokenExpiredException;
use App\Identity\Application\Exception\GoogleTokenInvalidException;
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
            throw new GoogleConfigurationException('Google client ID cannot be empty.');
        }

        if ('' === trim($idToken)) {
            throw new GoogleCredentialMissingException('Google ID token cannot be empty.');
        }

        // Parse JWT payload safely before cryptographic verification to identify specific failure causes
        $parts = explode('.', $idToken);
        if (3 !== \count($parts)) {
            throw new GoogleTokenInvalidException('Google ID token is malformed.');
        }

        $decodedPayload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!\is_array($decodedPayload)) {
            throw new GoogleTokenInvalidException('Google ID token payload is invalid JSON.');
        }

        $aud = $decodedPayload['aud'] ?? null;
        if ($aud !== $this->clientId) {
            throw new GoogleTokenAudienceInvalidException('Google ID token audience does not match configured client ID.');
        }

        $exp = (int) ($decodedPayload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            throw new GoogleTokenExpiredException('Google ID token is expired.');
        }

        $emailVerified = $decodedPayload['email_verified'] ?? false;
        if (true !== $emailVerified && 'true' !== $emailVerified) {
            throw new GoogleTokenEmailUnverifiedException('Google account email is not verified.');
        }

        $subject = (string) ($decodedPayload['sub'] ?? '');
        if ('' === trim($subject)) {
            throw new GoogleTokenInvalidException('Google ID token is missing subject claim.');
        }

        $email = (string) ($decodedPayload['email'] ?? '');
        if ('' === trim($email)) {
            throw new GoogleTokenInvalidException('Google ID token is missing email claim.');
        }

        // Perform cryptographic signature verification via Google Client SDK
        $client = new Client(['client_id' => $this->clientId]);

        try {
            $payload = $client->verifyIdToken($idToken);
        } catch (\Throwable $e) {
            throw new GoogleProviderUnavailableException('Google token verification failed due to provider error.', 0, $e);
        }

        if (false === $payload) {
            throw new GoogleTokenInvalidException('Google ID token signature verification failed.');
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
