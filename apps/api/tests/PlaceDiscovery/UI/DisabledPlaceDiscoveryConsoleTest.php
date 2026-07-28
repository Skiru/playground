<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\UI;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DisabledPlaceDiscoveryConsoleTest extends KernelTestCase
{
    #[RunInSeparateProcess]
    public function testMutationCommandsFailBeforeCreatingOrDispatchingRunsWhileDisabled(): void
    {
        $_ENV['PLACE_DISCOVERY_ENABLED'] = $_SERVER['PLACE_DISCOVERY_ENABLED'] = '0';
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $before = (int) $connection->fetchOne('SELECT COUNT(*) FROM place_discovery_runs');

        $run = new CommandTester($application->find('app:place-discovery:run'));
        self::assertSame(Command::FAILURE, $run->execute(['--area' => '00000000-0000-7000-8000-000000000900', '--release' => '2099-12-01.0']));
        self::assertStringContainsString('disabled', $run->getDisplay());

        $retry = new CommandTester($application->find('app:place-discovery:retry'));
        self::assertSame(Command::FAILURE, $retry->execute(['run' => '00000000-0000-7000-8000-000000000901']));
        self::assertStringContainsString('disabled', $retry->getDisplay());

        $check = new CommandTester($application->find('app:place-discovery:check-release'));
        self::assertSame(Command::FAILURE, $check->execute(['--dispatch' => true]));
        self::assertStringContainsString('disabled', $check->getDisplay());

        $reconcile = new CommandTester($application->find('app:place-discovery:reconcile-dispatch'));
        self::assertSame(Command::FAILURE, $reconcile->execute([]));
        self::assertStringContainsString('disabled', $reconcile->getDisplay());
        self::assertSame($before, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_discovery_runs'));
    }
}
