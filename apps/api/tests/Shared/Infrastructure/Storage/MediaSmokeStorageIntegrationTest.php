<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MediaSmokeStorageIntegrationTest extends KernelTestCase
{
    private StorageInterface $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->storage = $container->get(StorageInterface::class);

        // Cleanup before each test run
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->storage->delete('places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/source');
        $this->storage->delete('places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/variants/1/hero.webp');
    }

    public function testMediaSmokeWorkflow(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);

        $command = $application->find('app:media-smoke');
        $commandTester = new CommandTester($command);

        // 1. API writes sentinel
        $commandTester->execute(['--write-sentinel' => true]);
        $commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Private sentinel written successfully.', $commandTester->getDisplay());

        // 2. Worker reads sentinel and writes variant
        $commandTester->execute(['--process-sentinel' => true]);
        $commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Public variant written successfully.', $commandTester->getDisplay());

        // 3. API verifies public variant
        $commandTester->execute(['--verify-sentinel' => true]);
        $commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Public variant content verified successfully.', $commandTester->getDisplay());
    }
}
