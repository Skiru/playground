<?php

declare(strict_types=1);

namespace App\Tests\Places\Infrastructure\Doctrine;

use App\Places\Application\Command\AgeZoneInput;
use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\ConcurrentPlaceModification;
use App\Places\Application\PlaceCommandHandler;
use App\Places\Infrastructure\Doctrine\PlaceRepository;
use App\Shared\Infrastructure\Doctrine\DbalTransactionManager;
use App\Tests\Shared\Application\FrozenClock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlaceWriteModelIntegrationTest extends KernelTestCase
{
    public function testPublicationIsAtomicAndStaleVersionsCannotOverwriteChanges(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $handler = $this->handler($connection);
        $slug = 'c2r-write-model-integration';
        $connection->executeStatement('DELETE FROM places WHERE slug=:slug', ['slug' => $slug]);
        $id = $handler->create(new CreatePlaceDraft('C2R Write Model', $slug, 'Complete short description', 'Complete long description', 'Testowa 1', '00-001', 'warszawa', 'PL', 52.231, 21.019, 'Europe/Warsaw', 'bawialnie', true, false, false));

        $handler->replaceAgeZones(new ReplacePlaceAgeZones($id, 1, [new AgeZoneInput('Children', 12, 96)]));
        $handler->submit(new SubmitPlaceForReview($id, 2));
        $handler->publish(new PublishPlace($id, 3));

        $row = $connection->fetchAssociative('SELECT status,version,ST_X(location::geometry) longitude,ST_Y(location::geometry) latitude FROM places WHERE id=:id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame('published', $row['status']);
        self::assertSame(4, (int) $row['version']);
        self::assertSame(21.019, (float) $row['longitude']);
        self::assertSame(52.231, (float) $row['latitude']);

        $this->expectException(ConcurrentPlaceModification::class);
        try {
            $handler->archive(new ArchivePlace($id, 3));
        } finally {
            self::assertSame('published', $connection->fetchOne('SELECT status FROM places WHERE id=:id', ['id' => $id]));
        }
    }

    public function testTransactionManagerRollsBackTheWholeOperation(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $transactions = new DbalTransactionManager($connection);
        $id = '00000000-0000-7000-9000-000000000001';
        $connection->executeStatement('DELETE FROM amenities WHERE id=:id', ['id' => $id]);

        try {
            $transactions->transactional(static function () use ($connection, $id): void {
                $connection->insert('amenities', ['id' => $id, 'name' => 'Rollback', 'slug' => 'rollback-'.substr($id, -8), 'amenity_group' => 'test', 'icon_key' => 'test', 'enabled' => true, 'display_order' => 999]);
                throw new \RuntimeException('Force rollback.');
            });
            self::fail('The transaction should throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Force rollback.', $exception->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM amenities WHERE id=:id', ['id' => $id]));
    }

    private function handler(Connection $connection): PlaceCommandHandler
    {
        return new PlaceCommandHandler(new PlaceRepository($connection), new DbalTransactionManager($connection), new FrozenClock(new \DateTimeImmutable('2026-07-16T10:00:00Z')));
    }
}
