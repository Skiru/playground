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
    private ?Client $client;

    public function __construct(string $clientId, ?Client $client = null)
    {
        $this->clientId = $clientId;
        $this->client = $client;
    }

    public function verify(string $idToken): VerifiedGoogleIdentity
    {
        if ('' === trim($this->clientId)) {
            throw new GoogleConfigurationException('Google client ID cannot be empty.');
        }

        if ('' === trim($idToken)) {
            throw new GoogleCredentialMissingException('Google ID token cannot be empty.');
        }

        $parts = explode('.', $idToken);
        if (3 !== \count($parts)) {
            throw new GoogleTokenInvalidException('Google ID token is malformed.');
        }

        // Perform cryptographic signature verification via Google Client SDK FIRST
        $googleClient = $this->client ?? new Client(['client_id' => $this->clientId]);

        $payload = false;
        try {
            $payload = $googleClient->verifyIdToken($idToken);
        } catch (\InvalidArgumentException|\UnexpectedValueException|\DomainException $e) {
            // Local JWT decoding / signature verification failed
            $payload = false;
        } catch (\Throwable $e) {
            // Network/provider transport error
            throw new GoogleProviderUnavailableException('Google token verification failed due to provider error.', 0, $e);
        }

        if (false === $payload) {
            $decodedPayload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (\is_array($decodedPayload)) {
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
            }
            throw new GoogleTokenInvalidException('Google ID token signature verification failed.');
        }

        // Business checks on cryptographically verified payload
        $iss = (string) ($payload['iss'] ?? '');
        if ('accounts.google.com' !== $iss && 'https://accounts.google.com' !== $iss) {
            throw new GoogleTokenInvalidException('Google ID token issuer is invalid.');
        }

        $aud = $payload['aud'] ?? null;
        if ($aud !== $this->clientId) {
            throw new GoogleTokenAudienceInvalidException('Google ID token audience does not match configured client ID.');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            throw new GoogleTokenExpiredException('Google ID token is expired.');
        }

        $emailVerified = $payload['email_verified'] ?? false;
        if (true !== $emailVerified && 'true' !== $emailVerified) {
            throw new GoogleTokenEmailUnverifiedException('Google account email is not verified.');
        }

        $subject = (string) ($payload['sub'] ?? '');
        if ('' === trim($subject)) {
            throw new GoogleTokenInvalidException('Google ID token is missing subject claim.');
        }

        $email = (string) ($payload['email'] ?? '');
        if ('' === trim($email)) {
            throw new GoogleTokenInvalidException('Google ID token is missing email claim.');
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
