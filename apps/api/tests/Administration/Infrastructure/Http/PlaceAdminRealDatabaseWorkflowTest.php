<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaceAdminRealDatabaseWorkflowTest extends WebTestCase
{
    public function testStaleAdminEditShowsAReadableConflictWithoutOverwritingChanges(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $id = '00000000-0000-7000-8000-000000000400';
        $original = $connection->fetchAssociative('SELECT name,version FROM places WHERE id=:id', ['id' => $id]);
        self::assertIsArray($original);

        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $edit = $client->request('GET', '/admin/places/'.$id.'/edit');
        $staleVersion = (int) $original['version'];
        $connection->executeStatement('UPDATE places SET name=:name,version=version+1 WHERE id=:id', ['id' => $id, 'name' => 'Concurrent administrator update']);

        try {
            $payload = $this->formPayload($edit->filter('input[name="place_admin_form[_token]"]')->attr('value'), $staleVersion, 'Stale overwrite attempt', 'demo-1-demo-bawialnia-mokotow');
            $client->request('POST', '/admin/places/'.$id.'/edit', ['place_admin_form' => $payload]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'another administrator');
            self::assertSame('Concurrent administrator update', $connection->fetchOne('SELECT name FROM places WHERE id=:id', ['id' => $id]));
        } finally {
            $connection->executeStatement('UPDATE places SET name=:name,version=:version WHERE id=:id', ['id' => $id, 'name' => $original['name'], 'version' => $original['version']]);
        }
    }

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
        $createPayload = $this->formPayload($new->filter('input[name="place_admin_form[_token]"]')->attr('value'), null, 'C2R Admin Workflow', 'c2r-admin-workflow');
        $client->request('POST', '/admin/places/new', ['place_admin_form' => $createPayload]);
        self::assertResponseRedirects('/admin/places');
        $id = (string) $connection->fetchOne("SELECT id FROM places WHERE slug='c2r-admin-workflow'");
        self::assertNotSame('', $id);

        $this->workflowAction($client, $id, 'publish', 1);
        self::assertSame('draft', $connection->fetchOne('SELECT status FROM places WHERE id=:id', ['id' => $id]));

        $edit = $client->request('GET', '/admin/places/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $editPayload = $this->formPayload($edit->filter('input[name="place_admin_form[_token]"]')->attr('value'), 1, 'C2R Admin Workflow', 'c2r-admin-workflow');
        $editPayload['categorySlugs'] = ['bawialnie', 'parki'];
        $editPayload['amenitySlugs'] = ['parking', 'wifi'];
        $editPayload['ageZones'] = [['name' => 'Maluchy', 'minAgeMonths' => '6', 'maxAgeMonths' => '36', 'notes' => 'Opiekun wymagany'], ['name' => 'Dzieci', 'minAgeMonths' => '37', 'maxAgeMonths' => '120', 'notes' => '']];
        $editPayload['openingHoursMode'] = 'scheduled';
        $editPayload['weeklyOpeningHours'] = [['weekday' => '1', 'opensAt' => '09:00', 'closesAt' => '12:00'], ['weekday' => '1', 'opensAt' => '13:00', 'closesAt' => '18:00'], ['weekday' => '6', 'opensAt' => '20:00', 'closesAt' => '01:00', 'closesNextDay' => '1']];
        $editPayload['specialOpeningDays'] = [['localDate' => '2026-12-24', 'mode' => 'closed', 'note' => 'Wigilia', 'intervals' => []], ['localDate' => '2026-12-31', 'mode' => 'custom', 'note' => 'Sylwester', 'intervals' => [['opensAt' => '10:00', 'closesAt' => '14:00'], ['opensAt' => '20:00', 'closesAt' => '01:00', 'closesNextDay' => '1']]]];
        $editPayload['externalReferences'] = [['provider' => 'osm', 'externalId' => 'node-123', 'sourceUrl' => 'https://www.openstreetmap.org/node/123'], ['provider' => 'partner', 'externalId' => 'abc', 'sourceUrl' => '']];
        $client->request('POST', '/admin/places/'.$id.'/edit', ['place_admin_form' => $editPayload]);
        self::assertResponseRedirects('/admin/places/'.$id);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_age_zones WHERE place_id=:id', ['id' => $id]));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM weekly_opening_intervals WHERE place_id=:id', ['id' => $id]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM external_place_references WHERE place_id=:id', ['id' => $id]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT version FROM places WHERE id=:id', ['id' => $id]));

        $this->workflowAction($client, $id, 'submit', 2);
        $this->workflowAction($client, $id, 'publish', 3);
        self::assertSame('published', $connection->fetchOne('SELECT status FROM places WHERE id=:id', ['id' => $id]));
        $client->request('GET', '/api/v1/places/c2r-admin-workflow');
        self::assertResponseIsSuccessful();

        $this->workflowAction($client, $id, 'unpublish', 4);
        $client->request('GET', '/api/v1/places/c2r-admin-workflow');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOverlapErrorIsRenderedWithTheStructuredCollectionAndPreservesSubmittedData(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $id = '00000000-0000-7000-8000-000000000400';
        $this->login($client);
        $edit = $client->request('GET', '/admin/places/'.$id.'/edit');
        $version = (int) $connection->fetchOne('SELECT version FROM places WHERE id=:id', ['id' => $id]);
        $payload = $this->formPayload($edit->filter('input[name="place_admin_form[_token]"]')->attr('value'), $version, 'Preserved structured value', 'demo-1-demo-bawialnia-mokotow');
        $payload['openingHoursMode'] = 'scheduled';
        $payload['weeklyOpeningHours'] = [['weekday' => '1', 'opensAt' => '09:00', 'closesAt' => '13:00'], ['weekday' => '1', 'opensAt' => '12:00', 'closesAt' => '14:00']];

        $client->request('POST', '/admin/places/'.$id.'/edit', ['place_admin_form' => $payload]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'overlaps another weekly interval');
        self::assertSelectorExists('input[name="place_admin_form[core][name]"][value="Preserved structured value"]');
        self::assertSame($version, (int) $connection->fetchOne('SELECT version FROM places WHERE id=:id', ['id' => $id]));
    }

    public function testInvalidFormCsrfCannotCreateAPlace(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->login($client);
        $payload = $this->formPayload('invalid-token', null, 'Rejected CSRF', 'rejected-csrf-place');

        $client->request('POST', '/admin/places/new', ['place_admin_form' => $payload]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM places WHERE slug='rejected-csrf-place'"));
    }

    private function workflowAction(KernelBrowser $client, string $id, string $action, int $version): void
    {
        $view = $client->request('GET', '/admin/places/'.$id);
        self::assertResponseIsSuccessful();
        $token = $view->filter('form[action$="/'.$action.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/places/'.$id.'/'.$action, ['_token' => $token, 'version' => (string) $version]);
        self::assertResponseRedirects('/admin/places');
    }

    private function login(KernelBrowser $client): void
    {
        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');
    }

    /** @return array<string, mixed> */
    private function formPayload(string $token, ?int $version, string $name, string $slug): array
    {
        return [
            '_token' => $token,
            'expectedVersion' => null === $version ? '' : (string) $version,
            'core' => ['name' => $name, 'slug' => $slug, 'shortDescription' => 'Administrative workflow place', 'description' => 'Complete aggregate managed through typed commands.', 'addressLine1' => 'Testowa 10', 'addressLine2' => '', 'postalCode' => '00-010', 'citySlug' => 'warszawa', 'countryCode' => 'PL', 'latitude' => '52.24', 'longitude' => '21.02', 'timezone' => 'Europe/Warsaw', 'indoor' => '1', 'freeEntry' => '1', 'priceDescription' => 'Bezpłatnie', 'websiteUrl' => 'https://example.test/place', 'phone' => '+48123456789', 'verificationStatus' => 'unverified'],
            'categorySlugs' => ['bawialnie'],
            'primaryCategorySlug' => 'bawialnie',
            'amenitySlugs' => [],
            'ageZones' => [['name' => 'Children', 'minAgeMonths' => '0', 'maxAgeMonths' => '72', 'notes' => '']],
            'openingHoursMode' => 'unknown',
            'weeklyOpeningHours' => [],
            'specialOpeningDays' => [],
            'externalReferences' => [],
        ];
    }
}
