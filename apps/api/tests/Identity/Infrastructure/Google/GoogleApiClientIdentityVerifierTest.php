<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Google;

use App\Identity\Application\Exception\GoogleConfigurationException;
use App\Identity\Application\Exception\GoogleCredentialMissingException;
use App\Identity\Application\Exception\GoogleTokenAudienceInvalidException;
use App\Identity\Application\Exception\GoogleTokenEmailUnverifiedException;
use App\Identity\Application\Exception\GoogleTokenExpiredException;
use App\Identity\Application\Exception\GoogleTokenInvalidException;
use App\Identity\Infrastructure\Google\GoogleApiClientIdentityVerifier;
use PHPUnit\Framework\TestCase;

final class GoogleApiClientIdentityVerifierTest extends TestCase
{
    private function createJwtToken(array $payload): string
    {
        $header = base64_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payloadStr = strtr(base64_encode((string) json_encode($payload)), '+/', '-_');
        $signature = base64_encode('dummy_signature');

        return $header.'.'.$payloadStr.'.'.$signature;
    }

    public function testRejectsEmptyClientId(): void
    {
        $verifier = new GoogleApiClientIdentityVerifier('');
        $this->expectException(GoogleConfigurationException::class);
        $verifier->verify('some.jwt.token');
    }

    public function testRejectsEmptyToken(): void
    {
        $verifier = new GoogleApiClientIdentityVerifier('valid-client-id');
        $this->expectException(GoogleCredentialMissingException::class);
        $verifier->verify('   ');
    }

    public function testRejectsMalformedJwt(): void
    {
        $verifier = new GoogleApiClientIdentityVerifier('valid-client-id');
        $this->expectException(GoogleTokenInvalidException::class);
        $verifier->verify('not-a-valid-jwt');
    }

    public function testRejectsAudienceMismatch(): void
    {
        $verifier = new GoogleApiClientIdentityVerifier('expected-client-id');
        $token = $this->createJwtToken([
            'aud' => 'different-client-id',
            'exp' => time() + 3600,
            'email_verified' => true,
            'sub' => '12345',
            'email' => 'user@example.test',
        ]);

        $this->expectException(GoogleTokenAudienceInvalidException::class);
        $verifier->verify($token);
    }

    public function testRejectsExpiredToken(): void
    {
        $verifier = new GoogleApiClientIdentityVerifier('expected-client-id');
        $token = $this->createJwtToken([
            'aud' => 'expected-client-id',
            'exp' => time() - 3600,
            'email_verified' => true,
            'sub' => '12345',
            'email' => 'user@example.test',
        ]);

        $this->expectException(GoogleTokenExpiredException::class);
        $verifier->verify($token);
    }

    public function testRejectsUnverifiedEmail(): void
    {
        $verifier = new GoogleApiClientIdentityVerifier('expected-client-id');
        $token = $this->createJwtToken([
            'aud' => 'expected-client-id',
            'exp' => time() + 3600,
            'email_verified' => false,
            'sub' => '12345',
            'email' => 'user@example.test',
        ]);

        $this->expectException(GoogleTokenEmailUnverifiedException::class);
        $verifier->verify($token);
    }
}
