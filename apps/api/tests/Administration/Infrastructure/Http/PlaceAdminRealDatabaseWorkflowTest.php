<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaceAdminRealDatabaseWorkflowTest extends WebTestCase
{
    public function testAdministratorBuildsPublishesAndUnpublishesTheWholeAggregate(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("DELETE FROM places WHERE slug='c2r-admin-workflow'");

        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');

        $new = $client->request('GET', '/admin/places/new');
        $client->request('POST', '/admin/places/new', [
            '_token' => $new->filter('input[name="_token"]')->attr('value'), 'name' => 'C2R Admin Workflow', 'slug' => 'c2r-admin-workflow', 'shortDescription' => 'Administrative workflow place', 'description' => 'Complete aggregate managed through typed commands.', 'city' => 'warszawa', 'category' => 'bawialnie', 'addressLine1' => 'Testowa 10', 'postalCode' => '00-010', 'latitude' => '52.24', 'longitude' => '21.02', 'indoor' => '1',
        ]);
        self::assertResponseRedirects('/admin/places');
        $id = (string) $connection->fetchOne("SELECT id FROM places WHERE slug='c2r-admin-workflow'");
        self::assertNotSame('', $id);

        $this->workflowAction($client, $id, 'publish', 1);
        self::assertSame('draft', $connection->fetchOne('SELECT status FROM places WHERE id=:id', ['id' => $id]));

        $edit = $client->request('GET', '/admin/places/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/admin/places/'.$id.'/edit', [
            '_token' => $edit->filter('input[name="_token"]')->attr('value'), 'version' => '1', 'name' => 'C2R Admin Workflow', 'slug' => 'c2r-admin-workflow', 'shortDescription' => 'Administrative workflow place', 'description' => 'Complete aggregate managed through typed commands.', 'addressLine1' => 'Testowa 10', 'addressLine2' => 'Lokal 2', 'postalCode' => '00-010', 'city' => 'warszawa', 'countryCode' => 'PL', 'latitude' => '52.24', 'longitude' => '21.02', 'timezone' => 'Europe/Warsaw', 'indoor' => '1', 'freeEntry' => '1', 'priceDescription' => 'Bezpłatnie', 'websiteUrl' => 'https://example.test/place', 'phone' => '+48123456789', 'verificationStatus' => 'unverified', 'primaryCategory' => 'bawialnie', 'categories' => 'bawialnie,parki', 'amenities' => 'parking,wifi', 'ageZones' => "Maluchy|6|36|Opiekun wymagany\nDzieci|37|120|", 'weeklyOpeningHours' => "1|1|09:00|12:00|0\n1|2|13:00|18:00|0\n6|1|20:00|01:00|1", 'specialOpeningDays' => "2026-12-24|1|Wigilia|\n2026-12-31|0|Sylwester|1,10:00,14:00,0;2,20:00,01:00,1", 'externalReferences' => "osm|node-123|https://www.openstreetmap.org/node/123\npartner|abc|",
        ]);
        self::assertResponseRedirects('/admin/places/'.$id);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_age_zones WHERE place_id=:id', ['id' => $id]));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM weekly_opening_intervals WHERE place_id=:id', ['id' => $id]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM external_place_references WHERE place_id=:id', ['id' => $id]));

        $this->workflowAction($client, $id, 'submit', 8);
        $this->workflowAction($client, $id, 'publish', 9);
        self::assertSame('published', $connection->fetchOne('SELECT status FROM places WHERE id=:id', ['id' => $id]));
        $client->request('GET', '/api/v1/places/c2r-admin-workflow');
        self::assertResponseIsSuccessful();

        $this->workflowAction($client, $id, 'unpublish', 10);
        $client->request('GET', '/api/v1/places/c2r-admin-workflow');
        self::assertResponseStatusCodeSame(404);
    }

    private function workflowAction(KernelBrowser $client, string $id, string $action, int $version): void
    {
        $view = $client->request('GET', '/admin/places/'.$id);
        self::assertResponseIsSuccessful();
        $token = $view->filter('form[action$="/'.$action.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/places/'.$id.'/'.$action, ['_token' => $token, 'version' => (string) $version]);
        self::assertResponseRedirects('/admin/places');
    }
}
