<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application;

use App\Identity\Application\AuthenticateWithGoogle;
use App\Identity\Application\Exception\AccountLinkRequiredException;
use App\Identity\Application\ExternalIdentityRepository;
use App\Identity\Application\Google\GoogleIdentityVerifier;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ExternalIdentity;
use App\Identity\Domain\ExternalIdentityProvider;
use App\Identity\Domain\Google\VerifiedGoogleIdentity;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailAddress;
use PHPUnit\Framework\TestCase;

final class AuthenticateWithGoogleTest extends TestCase
{
    public function testItAuthenticatesNewUserSuccessfully(): void
    {
        $verifier = new class implements GoogleIdentityVerifier {
            public function verify(string $idToken): VerifiedGoogleIdentity
            {
                return new VerifiedGoogleIdentity(
                    subject: 'subject-123',
                    email: 'new-user@example.test',
                    emailVerified: true,
                    displayName: 'New User',
                    pictureUrl: null,
                    issuedAt: time(),
                    expiresAt: time() + 3600
                );
            }
        };

        $userRepository = new class implements UserRepository {
            public ?User $savedUser = null;

            public function findByEmail(EmailAddress $email): ?User
            {
                return null;
            }

            public function save(User $user): void
            {
                $this->savedUser = $user;
            }
        };

        $externalIdentityRepository = new class implements ExternalIdentityRepository {
            public ?ExternalIdentity $savedIdentity = null;

            public function findByProviderAndSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity
            {
                return null;
            }

            public function save(ExternalIdentity $identity): void
            {
                $this->savedIdentity = $identity;
            }
        };

        $transactionManager = new class implements \App\Shared\Application\TransactionManager {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };

        $clock = new class implements \App\Shared\Application\Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        $service = new AuthenticateWithGoogle($verifier, $externalIdentityRepository, $userRepository, $transactionManager, $clock);
        $user = $service->authenticate('fake-google-token');

        self::assertSame($user, $userRepository->savedUser);
        self::assertSame('new-user@example.test', $user->email()->value);
        self::assertSame('New User', $user->getDisplayName());
        self::assertNotNull($externalIdentityRepository->savedIdentity);
        self::assertSame($user, $externalIdentityRepository->savedIdentity->getUser());
    }

    public function testItAuthenticatesExistingUserSuccessfully(): void
    {
        $now = new \DateTimeImmutable();
        $existingUser = new User(new EmailAddress('existing@example.test'), 'Existing User', $now);
        $existingIdentity = new ExternalIdentity($existingUser, ExternalIdentityProvider::GOOGLE, 'subject-123', 'existing@example.test', $now);

        $verifier = new class implements GoogleIdentityVerifier {
            public function verify(string $idToken): VerifiedGoogleIdentity
            {
                return new VerifiedGoogleIdentity(
                    subject: 'subject-123',
                    email: 'existing@example.test',
                    emailVerified: true,
                    displayName: 'Existing User',
                    pictureUrl: null,
                    issuedAt: time(),
                    expiresAt: time() + 3600
                );
            }
        };

        $userRepository = new class implements UserRepository {
            public ?User $savedUser = null;

            public function findByEmail(EmailAddress $email): ?User
            {
                return null;
            }

            public function save(User $user): void
            {
                $this->savedUser = $user;
            }
        };

        $externalIdentityRepository = new class($existingIdentity) implements ExternalIdentityRepository {
            public ?ExternalIdentity $savedIdentity = null;

            public function __construct(private ?ExternalIdentity $identity)
            {
            }

            public function findByProviderAndSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity
            {
                return $this->identity;
            }

            public function save(ExternalIdentity $identity): void
            {
                $this->savedIdentity = $identity;
            }
        };

        $transactionManager = new class implements \App\Shared\Application\TransactionManager {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };

        $clock = new class implements \App\Shared\Application\Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        $service = new AuthenticateWithGoogle($verifier, $externalIdentityRepository, $userRepository, $transactionManager, $clock);
        $user = $service->authenticate('fake-google-token');

        self::assertSame($existingUser, $user);
        self::assertNotNull($user->lastLoginAt());
        self::assertNotNull($externalIdentityRepository->savedIdentity);
    }

    public function testItPreventsEmailTakeoverOnCollision(): void
    {
        $now = new \DateTimeImmutable();
        $existingUser = new User(new EmailAddress('existing@example.test'), 'Existing User', $now);

        $verifier = new class implements GoogleIdentityVerifier {
            public function verify(string $idToken): VerifiedGoogleIdentity
            {
                return new VerifiedGoogleIdentity(
                    subject: 'subject-123',
                    email: 'existing@example.test',
                    emailVerified: true,
                    displayName: 'Malicious Hijacker',
                    pictureUrl: null,
                    issuedAt: time(),
                    expiresAt: time() + 3600
                );
            }
        };

        $userRepository = new class($existingUser) implements UserRepository {
            public function __construct(private User $user)
            {
            }

            public function findByEmail(EmailAddress $email): ?User
            {
                return $this->user;
            }

            public function save(User $user): void
            {
            }
        };

        $externalIdentityRepository = new class implements ExternalIdentityRepository {
            public function findByProviderAndSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity
            {
                return null;
            }

            public function save(ExternalIdentity $identity): void
            {
            }
        };

        $transactionManager = new class implements \App\Shared\Application\TransactionManager {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }
        };

        $clock = new class implements \App\Shared\Application\Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        $service = new AuthenticateWithGoogle($verifier, $externalIdentityRepository, $userRepository, $transactionManager, $clock);

        $this->expectException(AccountLinkRequiredException::class);
        $service->authenticate('fake-google-token');
    }
}
