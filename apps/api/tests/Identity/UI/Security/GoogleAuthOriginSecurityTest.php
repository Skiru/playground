<?php

declare(strict_types=1);

namespace App\Tests\Identity\UI\Security;

use App\Identity\Application\AuthenticateWithGoogle;
use App\Identity\UI\Security\GoogleCredentialAuthenticator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class GoogleAuthOriginSecurityTest extends KernelTestCase
{
    private function createAuthenticator(string $publicOrigin, string $trustedOrigins = ''): GoogleCredentialAuthenticator
    {
        self::bootKernel();
        $container = static::getContainer();

        $verifier = $this->createMock(\App\Identity\Application\Google\GoogleIdentityVerifier::class);
        $extRepo = $this->createMock(\App\Identity\Application\ExternalIdentityRepository::class);
        $userRepo = $this->createMock(\App\Identity\Application\UserRepository::class);
        $txManager = $this->createMock(\App\Shared\Application\TransactionManager::class);
        $clock = $this->createMock(\App\Shared\Application\Clock::class);

        $verifier->method('verify')->willThrowException(new \App\Identity\Application\Exception\GoogleCredentialInvalidException('Invalid token'));

        $authService = new AuthenticateWithGoogle($verifier, $extRepo, $userRepo, $txManager, $clock);
        $csrfManager = $container->get(CsrfTokenManagerInterface::class);
        $limiterFactory = $container->get('limiter.google_login');

        return new GoogleCredentialAuthenticator(
            $authService,
            $csrfManager,
            new NullLogger(),
            $limiterFactory,
            $publicOrigin,
            $trustedOrigins
        );
    }

    private function createRequest(?string $origin, string $clientIp = '127.0.0.1'): Request
    {
        $headers = [
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $clientIp,
        ];
        if (null !== $origin) {
            $headers['HTTP_ORIGIN'] = $origin;
        }

        return Request::create(
            '/api/v1/auth/google',
            'POST',
            [],
            [],
            [],
            $headers,
            (string) json_encode(['credential' => 'dummy-google-token'])
        );
    }

    public function testRejectsMissingOrigin(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest(null, '10.0.0.1');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('GOOGLE_CREDENTIAL_INVALID');

        $authenticator->authenticate($request);
    }

    public function testRejectsEmptyOrigin(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest('   ', '10.0.0.2');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('GOOGLE_CREDENTIAL_INVALID');

        $authenticator->authenticate($request);
    }

    public function testRejectsUntrustedOrigin(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest('http://evil-attacker.test', '10.0.0.3');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('GOOGLE_CREDENTIAL_INVALID');

        $authenticator->authenticate($request);
    }

    public function testRejectsOriginWithPath(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest('http://localhost:3000/stolen-path', '10.0.0.4');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('GOOGLE_CREDENTIAL_INVALID');

        $authenticator->authenticate($request);
    }

    public function testRejectsSchemeMismatch(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest('https://localhost:3000', '10.0.0.5');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('GOOGLE_CREDENTIAL_INVALID');

        $authenticator->authenticate($request);
    }

    public function testRejectsPortMismatch(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest('http://localhost:8080', '10.0.1.6');

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('GOOGLE_CREDENTIAL_INVALID');

        $authenticator->authenticate($request);
    }

    public function testAcceptsExactPublicOrigin(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000');
        $request = $this->createRequest('http://localhost:3000', '10.0.0.7');

        try {
            $authenticator->authenticate($request);
            $this->fail('Expected exception during token validation, not origin failure.');
        } catch (BadCredentialsException $e) {
            $this->assertSame('GOOGLE_CREDENTIAL_INVALID', $e->getMessage());
        }
    }

    public function testAcceptsSecondaryTrustedOrigin(): void
    {
        $authenticator = $this->createAuthenticator('http://localhost:3000', 'https://app.familyplaces.pl, http://127.0.0.1:3000');
        $request = $this->createRequest('https://app.familyplaces.pl', '10.0.0.8');

        try {
            $authenticator->authenticate($request);
            $this->fail('Expected exception during token validation.');
        } catch (BadCredentialsException $e) {
            $this->assertSame('GOOGLE_CREDENTIAL_INVALID', $e->getMessage());
        }
    }
}
