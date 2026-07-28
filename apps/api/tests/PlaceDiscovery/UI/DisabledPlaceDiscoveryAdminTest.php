<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\UI;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DisabledPlaceDiscoveryAdminTest extends WebTestCase
{
    #[RunInSeparateProcess]
    public function testRunNowAndRetryAreControlledWithoutCreatingRunsWhileDisabled(): void
    {
        $_ENV['PLACE_DISCOVERY_ENABLED'] = $_SERVER['PLACE_DISCOVERY_ENABLED'] = '0';
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $page = $client->request('GET', '/admin?routeName=admin_place_discovery_runs');
        self::assertSelectorTextContains('body', 'Odkrywanie miejsc jest wyłączone');
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $before = (int) $connection->fetchOne('SELECT COUNT(*) FROM place_discovery_runs');
        $client->request('POST', '/admin/place-discovery/runs/action', ['_token' => $token, 'action' => 'run-now', 'area_id' => '00000000-0000-7000-8000-000000000900']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Place discovery is disabled');
        self::assertSame($before, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_discovery_runs'));
    }
}
