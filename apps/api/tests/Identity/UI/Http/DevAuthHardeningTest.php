<?php

declare(strict_types=1);

namespace App\Tests\Identity\UI\Http;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class DevAuthHardeningTest extends TestCase
{
    public function testBootFailsInProductionWhenDevAuthIsEnabled(): void
    {
        $_ENV['DEV_AUTH_ENABLED'] = 'true';
        $kernel = new Kernel('prod', false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CRITICAL SECURITY CONFIGURATION FAILURE: DEV_AUTH_ENABLED cannot be enabled in production environment.');

        try {
            $kernel->boot();
        } finally {
            unset($_ENV['DEV_AUTH_ENABLED']);
        }
    }

    public function testBootSucceedsInProductionWhenDevAuthIsDisabled(): void
    {
        $_ENV['DEV_AUTH_ENABLED'] = 'false';
        $kernel = new Kernel('prod', false);

        // Should not throw security configuration exception on boot
        try {
            $kernel->boot();
            $this->assertSame('prod', $kernel->getEnvironment());
        } finally {
            unset($_ENV['DEV_AUTH_ENABLED']);
            $kernel->shutdown();
        }
    }
}
