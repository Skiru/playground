<?php

declare(strict_types=1);

namespace App\Tests\Administration\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DictionaryAdminControllerTest extends WebTestCase
{
    /** @return iterable<string, array{string, array<string, string>}> */
    public static function dictionaries(): iterable
    {
        yield 'city' => ['cities', ['name' => 'Test City', 'slug' => 'c2r-test-city', 'countryCode' => 'PL', 'latitude' => '51.1', 'longitude' => '17.1', 'defaultZoom' => '12', 'defaultRadiusKm' => '20', 'timezone' => 'Europe/Warsaw', 'enabled' => '1']];
        yield 'category' => ['categories', ['name' => 'Test Category', 'slug' => 'c2r-test-category', 'description' => 'Test', 'iconKey' => 'test', 'displayOrder' => '99', 'enabled' => '1']];
        yield 'amenity' => ['amenities', ['name' => 'Test Amenity', 'slug' => 'c2r-test-amenity', 'group' => 'test', 'iconKey' => 'test', 'displayOrder' => '99', 'enabled' => '1']];
    }

    /** @param array<string, string> $payload */
    #[DataProvider('dictionaries')]
    public function testAdministratorCanCreateEditAndDeleteDictionaryEntries(string $type, array $payload): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $table = $type;
        $connection->executeStatement('DELETE FROM '.$table.' WHERE slug=:slug', ['slug' => $payload['slug']]);
        $this->login($client);

        $new = $client->request('GET', '/admin/dictionaries/'.$type.'/new');
        $client->request('POST', '/admin/dictionaries/'.$type.'/new', ['_token' => $new->filter('input[name="_token"]')->attr('value')] + $payload);
        self::assertResponseRedirects('/admin/dictionaries/'.$type);
        $id = (string) $connection->fetchOne('SELECT id FROM '.$table.' WHERE slug=:slug', ['slug' => $payload['slug']]);
        self::assertNotSame('', $id);

        $edit = $client->request('GET', '/admin/dictionaries/'.$type.'/'.$id.'/edit');
        $client->request('POST', '/admin/dictionaries/'.$type.'/'.$id.'/edit', ['_token' => $edit->filter('input[name="_token"]')->attr('value')] + array_replace($payload, ['name' => 'Updated '.$payload['name'], 'enabled' => '']));
        self::assertResponseRedirects('/admin/dictionaries/'.$type);
        self::assertSame('Updated '.$payload['name'], $connection->fetchOne('SELECT name FROM '.$table.' WHERE id=:id', ['id' => $id]));

        $list = $client->request('GET', '/admin/dictionaries/'.$type);
        $token = $list->filter('form[action*="'.$id.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/dictionaries/'.$type.'/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/dictionaries/'.$type);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE id=:id', ['id' => $id]));
    }

    public function testUsedDictionaryEntryCannotBeDeleted(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $id = (string) $connection->fetchOne("SELECT id FROM categories WHERE slug='bawialnie'");
        $this->login($client);
        $list = $client->request('GET', '/admin/dictionaries/categories');
        $token = $list->filter('form[action*="'.$id.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/dictionaries/categories/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/dictionaries/categories');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM categories WHERE id=:id', ['id' => $id]));
    }

    private function login(KernelBrowser $client): void
    {
        $login = $client->request('GET', '/admin/login');
        $client->request('POST', '/admin/login', ['_username' => 'admin@example.test', '_password' => 'test-password', '_csrf_token' => $login->filter('input[name="_csrf_token"]')->attr('value')], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/admin');
    }
}
