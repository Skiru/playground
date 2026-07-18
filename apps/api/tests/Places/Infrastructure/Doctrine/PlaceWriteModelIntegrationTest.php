<?php

declare(strict_types=1);

namespace App\Tests\Places\Infrastructure\Doctrine;

use App\Places\Application\Command\AgeZoneInput;
use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\Command\OpeningHoursModeInput;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\SpecialOpeningDayInput;
use App\Places\Application\Command\SpecialOpeningDayModeInput;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UpdatePlaceAggregate;
use App\Places\Application\Command\VerificationStatusInput;
use App\Places\Application\Command\WeeklyOpeningIntervalInput;
use App\Places\Application\ConcurrentPlaceModification;
use App\Places\Application\PlaceCommandHandler;
use App\Places\Infrastructure\Doctrine\PlaceRepository;
use App\Shared\Infrastructure\Doctrine\DbalTransactionManager;
use App\Tests\Shared\Application\FrozenClock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlaceWriteModelIntegrationTest extends KernelTestCase
{
    public function testCompleteDraftIsPersistedAtomicallyAtInitialVersion(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $slug = 'c2r2-atomic-complete-draft';
        $connection->executeStatement('DELETE FROM places WHERE slug=:slug', ['slug' => $slug]);

        $id = $this->handler($connection)->create($this->completeDraft($slug));

        $place = $connection->fetchAssociative('SELECT version,opening_hours_mode FROM places WHERE id=:id', ['id' => $id]);
        self::assertIsArray($place);
        self::assertSame(1, (int) $place['version']);
        self::assertSame('scheduled', $place['opening_hours_mode']);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_categories WHERE place_id=:id', ['id' => $id]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_amenities WHERE place_id=:id', ['id' => $id]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_age_zones WHERE place_id=:id', ['id' => $id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM weekly_opening_intervals WHERE place_id=:id', ['id' => $id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM special_opening_days WHERE place_id=:id', ['id' => $id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM external_place_references WHERE place_id=:id', ['id' => $id]));
    }

    public function testInvalidAggregateInputsLeaveNoDraftRows(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $handler = $this->handler($connection);

        $cases = [
            'c2r2-invalid-age' => fn (string $slug): CreatePlaceDraft => $this->completeDraft($slug, ageZones: [new AgeZoneInput('Invalid', 24, 12)]),
            'c2r2-invalid-category' => fn (string $slug): CreatePlaceDraft => $this->completeDraft($slug, categorySlugs: ['missing-category']),
            'c2r2-invalid-schedule' => fn (string $slug): CreatePlaceDraft => $this->completeDraft($slug, weekly: [new WeeklyOpeningIntervalInput(1, 1, '09:00', '13:00', false), new WeeklyOpeningIntervalInput(1, 2, '12:00', '14:00', false)]),
            'c2r2-duplicate-reference' => fn (string $slug): CreatePlaceDraft => $this->completeDraft($slug, references: [new ExternalReferenceInput('duplicate', 'same'), new ExternalReferenceInput('duplicate', 'same')]),
        ];

        foreach ($cases as $slug => $factory) {
            $connection->executeStatement('DELETE FROM places WHERE slug=:slug', ['slug' => $slug]);
            try {
                $handler->create($factory($slug));
                self::fail('Invalid aggregate must be rejected for '.$slug);
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM places WHERE slug=:slug', ['slug' => $slug]));
            }
        }
    }

    public function testRelationPersistenceFailureRollsBackThePlaceAndEveryEarlierRelation(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $handler = $this->handler($connection);
        $existingSlug = 'c2r2-reference-owner';
        $failedSlug = 'c2r2-reference-conflict';
        $connection->executeStatement('DELETE FROM places WHERE slug IN (:existing,:failed)', ['existing' => $existingSlug, 'failed' => $failedSlug]);
        $handler->create($this->completeDraft($existingSlug, references: [new ExternalReferenceInput('rollback-provider', 'shared-id')]));

        try {
            $handler->create($this->completeDraft($failedSlug, references: [new ExternalReferenceInput('rollback-provider', 'shared-id')]));
            self::fail('The unique relation failure must be propagated.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM places WHERE slug=:slug', ['slug' => $failedSlug]));
        }
    }

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

    public function testFailureInLastEditedCollectionRollsBackCoreAndEveryEarlierCollection(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $handler = $this->handler($connection);
        $ownerSlug = 'c2r2-update-reference-owner';
        $editedSlug = 'c2r2-update-rollback';
        $connection->executeStatement('DELETE FROM places WHERE slug IN (:owner,:edited)', ['owner' => $ownerSlug, 'edited' => $editedSlug]);
        $handler->create($this->completeDraft($ownerSlug, references: [new ExternalReferenceInput('update-rollback', 'shared-id')]));
        $editedId = $handler->create($this->completeDraft($editedSlug));

        try {
            $handler->update(new UpdatePlaceAggregate($editedId, 1, 'Must roll back', $editedSlug, 'Changed short', 'Changed description', 'Changed 2', '00-002', 'warszawa', 'PL', 52.3, 21.1, 'Europe/Warsaw', true, true, true, VerificationStatusInput::UNVERIFIED, ['bawialnie'], 'bawialnie', [], [new AgeZoneInput('Changed zone', 0, 12)], OpeningHoursModeInput::UNKNOWN, [], [], [new ExternalReferenceInput('update-rollback', 'shared-id')]));
            self::fail('The last collection persistence failure must be propagated.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $row = $connection->fetchAssociative('SELECT name,version FROM places WHERE id=:id', ['id' => $editedId]);
            self::assertIsArray($row);
            self::assertSame('Atomic draft', $row['name']);
            self::assertSame(1, (int) $row['version']);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_age_zones WHERE place_id=:id', ['id' => $editedId]));
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM place_amenities WHERE place_id=:id', ['id' => $editedId]));
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
        $storage = $this->createMock(\App\Shared\Application\Storage\StorageInterface::class);
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        return new PlaceCommandHandler(new PlaceRepository($connection), new DbalTransactionManager($connection), new FrozenClock(new \DateTimeImmutable('2026-07-16T10:00:00Z')), $storage, $bus);
    }

    /**
     * @param list<AgeZoneInput>|null               $ageZones
     * @param list<string>|null                     $categorySlugs
     * @param list<WeeklyOpeningIntervalInput>|null $weekly
     * @param list<ExternalReferenceInput>|null     $references
     */
    private function completeDraft(string $slug, ?array $ageZones = null, ?array $categorySlugs = null, ?array $weekly = null, ?array $references = null): CreatePlaceDraft
    {
        return new CreatePlaceDraft(
            name: 'Atomic draft',
            slug: $slug,
            shortDescription: 'Complete short description',
            description: 'Complete aggregate persisted in one transaction.',
            addressLine1: 'Testowa 1',
            postalCode: '00-001',
            citySlug: 'warszawa',
            countryCode: 'PL',
            latitude: 52.231,
            longitude: 21.019,
            timezone: 'Europe/Warsaw',
            categorySlug: 'bawialnie',
            indoor: true,
            outdoor: false,
            freeEntry: false,
            categorySlugs: $categorySlugs ?? ['bawialnie', 'parki'],
            amenitySlugs: ['parking', 'wifi'],
            ageZones: $ageZones ?? [new AgeZoneInput('Toddlers', 12, 36), new AgeZoneInput('Children', 37, 96)],
            openingHoursMode: OpeningHoursModeInput::SCHEDULED,
            weeklyOpeningHours: $weekly ?? [new WeeklyOpeningIntervalInput(1, 1, '09:00', '18:00', false)],
            specialOpeningDays: [new SpecialOpeningDayInput('2026-12-24', SpecialOpeningDayModeInput::CLOSED, 'Closed', [])],
            externalReferences: $references ?? [new ExternalReferenceInput('atomic-test', $slug)],
        );
    }
}
