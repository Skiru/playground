<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application;

use App\Identity\Application\CreateAdmin;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailAddress;
use App\Tests\Shared\Application\FrozenClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminTest extends TestCase
{
    public function testItHashesAndPersistsAnAdministrator(): void
    {
        $repository = new class implements UserRepository {
            public ?User $saved = null;

            public function findByEmail(EmailAddress $email): ?User
            {
                return null;
            }

            public function save(User $user): void
            {
                $this->saved = $user;
            }
        };
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('secure-hash');
        $service = new CreateAdmin($repository, $hasher, new FrozenClock(new \DateTimeImmutable('2026-07-16T08:00:00Z')));

        $user = $service->execute('admin@example.test', 'long-secure-password', 'Admin');

        self::assertSame($user, $repository->saved);
        self::assertSame('secure-hash', $user->getPassword());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }
}
