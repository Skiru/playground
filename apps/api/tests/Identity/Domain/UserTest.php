<?php

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailAddress;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testAdministratorUsesNormalizedUniqueIdentifierAndRole(): void
    {
        $user = User::administrator(new EmailAddress(' Admin@Example.COM '), 'Administrator', new \DateTimeImmutable('2026-07-16T08:00:00Z'));
        self::assertSame('admin@example.com', $user->getUserIdentifier());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }
}
