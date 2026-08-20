<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application;

use App\Identity\Application\AuthenticateWithGoogle;
use App\Identity\Application\Exception\AccountLinkRequiredException;
use App\Identity\Application\ExternalIdentityRepository;
use App\Identity\Application\Google\GoogleIdentityVerifier;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\Google\VerifiedGoogleIdentity;
use App\Shared\Application\Clock;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GoogleConcurrentFirstLoginTest extends KernelTestCase
{
    private Connection $connection;
    private AuthenticateWithGoogle $authenticateWithGoogle;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $extRepo = $container->get(ExternalIdentityRepository::class);
        $userRepo = $container->get(UserRepository::class);
        $txManager = $container->get(TransactionManager::class);
        $clock = $container->get(Clock::class);

        $verifier = $this->createMock(GoogleIdentityVerifier::class);
        $verifier->method('verify')->willReturnCallback(static function (string $token) {
            $data = json_decode($token, true);

            return new VerifiedGoogleIdentity(
                subject: $data['subject'],
                email: $data['email'],
                emailVerified: true,
                displayName: $data['displayName'] ?? 'Test User',
                pictureUrl: null,
                issuedAt: time(),
                expiresAt: time() + 3600
            );
        });

        $this->authenticateWithGoogle = new AuthenticateWithGoogle(
            $verifier,
            $extRepo,
            $userRepo,
            $txManager,
            $clock
        );
    }

    public function testDifferentSubjectsSameEmailConcurrentFirstLogin(): void
    {
        $email = 'concurrent_test_'.bin2hex(random_bytes(4)).'@example.test';
        $tokenA = (string) json_encode(['subject' => 'sub_A_'.bin2hex(random_bytes(4)), 'email' => $email, 'displayName' => 'User A']);
        $tokenB = (string) json_encode(['subject' => 'sub_B_'.bin2hex(random_bytes(4)), 'email' => $email, 'displayName' => 'User B']);

        // First login for Subject A
        $userA = $this->authenticateWithGoogle->authenticate($tokenA);
        $this->assertNotNull($userA);
        $this->assertSame($email, (string) $userA->email());

        // Concurrent/second login attempt for Subject B with SAME email address
        $this->expectException(AccountLinkRequiredException::class);
        $this->expectExceptionMessage('An account with this email address already exists. Manual linking is required.');

        $this->authenticateWithGoogle->authenticate($tokenB);

        // Verify DB invariants
        $userCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE email = :email', ['email' => $email]);
        $this->assertSame(1, $userCount, 'Exactly 1 user must exist in database for this email');

        $identityCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM external_identities WHERE provider_email = :email', ['email' => $email]);
        $this->assertSame(1, $identityCount, 'Exactly 1 external identity must exist in database for this email');
    }

    public function testSameSubjectSameEmailIdempotentLogin(): void
    {
        $email = 'idempotent_test_'.bin2hex(random_bytes(4)).'@example.test';
        $sub = 'sub_idempotent_'.bin2hex(random_bytes(4));
        $token = (string) json_encode(['subject' => $sub, 'email' => $email, 'displayName' => 'User Same']);

        $user1 = $this->authenticateWithGoogle->authenticate($token);
        $user2 = $this->authenticateWithGoogle->authenticate($token);

        $this->assertSame($user1->getId()->toString(), $user2->getId()->toString());

        $userCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE email = :email', ['email' => $email]);
        $this->assertSame(1, $userCount);

        $identityCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM external_identities WHERE provider_subject = :sub', ['sub' => $sub]);
        $this->assertSame(1, $identityCount);
    }
}
