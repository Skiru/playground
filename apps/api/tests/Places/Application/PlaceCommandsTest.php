<?php

declare(strict_types=1);

namespace App\Tests\Places\Application;

use App\Places\Application\Command\AgeZoneInput;
use App\Places\Application\Command\ArchivePlace;
use App\Places\Application\Command\CreatePlaceDraft;
use App\Places\Application\Command\ExternalReferenceInput;
use App\Places\Application\Command\MarkPlaceNeedsReverification;
use App\Places\Application\Command\MarkPlaceTemporarilyClosed;
use App\Places\Application\Command\OpeningHoursModeInput;
use App\Places\Application\Command\PublishPlace;
use App\Places\Application\Command\ReplaceExternalReferences;
use App\Places\Application\Command\ReplacePlaceAgeZones;
use App\Places\Application\Command\ReplacePlaceAmenities;
use App\Places\Application\Command\ReplacePlaceCategories;
use App\Places\Application\Command\ReplaceSpecialOpeningDays;
use App\Places\Application\Command\ReplaceWeeklyOpeningHours;
use App\Places\Application\Command\SpecialOpeningDayInput;
use App\Places\Application\Command\SpecialOpeningDayModeInput;
use App\Places\Application\Command\SubmitPlaceForReview;
use App\Places\Application\Command\UnpublishPlace;
use App\Places\Application\Command\UpdatePlaceAggregate;
use App\Places\Application\Command\UpdatePlaceCoreDetails;
use App\Places\Application\Command\VerificationStatusInput;
use App\Places\Application\Command\WeeklyOpeningIntervalInput;
use App\Places\Application\PlaceCommandHandler;
use App\Places\Application\PlaceRepository;
use App\Places\Domain\Category;
use App\Places\Domain\City;
use App\Places\Domain\Place;
use App\Places\Domain\ValueObject\Coordinates;
use App\Shared\Application\Clock;
use App\Shared\Application\TransactionManager;
use PHPUnit\Framework\TestCase;

final class PlaceCommandsTest extends TestCase
{
    public function testEveryAdministrationCommandHasAnExplicitTypedContract(): void
    {
        $commands = [
            new CreatePlaceDraft('Place', 'place', 'Short', 'Description', 'Street 1', '00-001', 'warszawa', 'PL', 52.2, 21.0, 'Europe/Warsaw', 'parks', true, false, false),
            new UpdatePlaceAggregate('id', 1, 'Place', 'place', 'Short', 'Description', 'Street 1', '00-001', 'warszawa', 'PL', 52.2, 21.0, 'Europe/Warsaw', true, false, false, VerificationStatusInput::UNVERIFIED, ['parks'], 'parks', [], [], OpeningHoursModeInput::UNKNOWN, [], [], []),
            new UpdatePlaceCoreDetails('id', 1, 'Place', 'place', 'Short', 'Description', 'Street 1', '00-001', 'warszawa', 'PL', 52.2, 21.0, 'Europe/Warsaw', true, false, false, VerificationStatusInput::UNVERIFIED),
            new ReplacePlaceCategories('id', 1, ['parks'], 'parks'),
            new ReplacePlaceAmenities('id', 1, ['parking']),
            new ReplacePlaceAgeZones('id', 1, [new AgeZoneInput('Children', 12, 72)]),
            new ReplaceWeeklyOpeningHours('id', 1, [new WeeklyOpeningIntervalInput(1, 1, '09:00', '18:00', false)]),
            new ReplaceSpecialOpeningDays('id', 1, [new SpecialOpeningDayInput('2026-12-24', SpecialOpeningDayModeInput::CLOSED, null, [])]),
            new ReplaceExternalReferences('id', 1, [new ExternalReferenceInput('osm', '123')]),
            new SubmitPlaceForReview('id', 1),
            new PublishPlace('id', 1),
            new UnpublishPlace('id', 1),
            new MarkPlaceNeedsReverification('id', 1),
            new MarkPlaceTemporarilyClosed('id', 1),
            new ArchivePlace('id', 1),
        ];

        self::assertCount(15, $commands);
    }

    public function testMutationCommandsRejectInvalidVersions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PublishPlace('id', 0);
    }

    public function testCompleteDraftUsesOneTransactionOneBatchPerDictionaryAndOneAggregateAdd(): void
    {
        $now = new \DateTimeImmutable('2026-07-16T10:00:00Z');
        $city = new City('Warszawa', 'warszawa', 'PL', new Coordinates(52.2, 21.0), 12, 15, 'Europe/Warsaw', true, $now);
        $primary = new Category('Parks', 'parks', null, 'parks', true, 1);
        $secondary = new Category('Museums', 'museums', null, 'museums', true, 2);
        $places = $this->createMock(PlaceRepository::class);
        $places->expects(self::once())->method('cityBySlug')->with('warszawa')->willReturn($city);
        $places->expects(self::once())->method('categoriesBySlugs')->with(['parks', 'museums'])->willReturn([$primary, $secondary]);
        $places->expects(self::once())->method('amenitiesBySlugs')->with([])->willReturn([]);
        $places->expects(self::never())->method('categoryBySlug');
        $places->expects(self::once())->method('add')->willReturnCallback(static function (Place $place): void {
            self::assertSame(1, $place->version());
            self::assertCount(2, $place->categories());
            self::assertCount(1, $place->ageZones());
            self::assertCount(1, $place->weeklyOpeningHours());
            self::assertCount(1, $place->specialOpeningDays());
            self::assertCount(1, $place->externalReferences());
        });
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $handler = new PlaceCommandHandler($places, $transactions, $clock);

        $handler->create(new CreatePlaceDraft(
            'Place',
            'place',
            'Short',
            'Description',
            'Street 1',
            '00-001',
            'warszawa',
            'PL',
            52.2,
            21.0,
            'Europe/Warsaw',
            'parks',
            true,
            false,
            false,
            categorySlugs: ['parks', 'museums'],
            ageZones: [new AgeZoneInput('Children', 12, 72)],
            openingHoursMode: OpeningHoursModeInput::SCHEDULED,
            weeklyOpeningHours: [new WeeklyOpeningIntervalInput(1, 1, '09:00', '18:00', false)],
            specialOpeningDays: [new SpecialOpeningDayInput('2026-12-24', SpecialOpeningDayModeInput::CLOSED, null, [])],
            externalReferences: [new ExternalReferenceInput('osm', '123')],
        ));
    }

    public function testCompleteEditLoadsAndSavesTheAggregateExactlyOnce(): void
    {
        $now = new \DateTimeImmutable('2026-07-16T10:00:00Z');
        $city = new City('Warszawa', 'warszawa', 'PL', new Coordinates(52.2, 21.0), 12, 15, 'Europe/Warsaw', true, $now);
        $primary = new Category('Parks', 'parks', null, 'parks', true, 1);
        $place = new Place(new \App\Places\Domain\ValueObject\PlaceName('Before'), new \App\Places\Domain\ValueObject\PlaceSlug('before'), 'Short', 'Description', 'Street 1', '00-001', $city, 'PL', new Coordinates(52.2, 21.0), 'Europe/Warsaw', $primary, true, false, false, $now);
        $places = $this->createMock(PlaceRepository::class);
        $places->expects(self::once())->method('get')->with($place->id()->toRfc4122())->willReturn($place);
        $places->expects(self::once())->method('cityBySlug')->with('warszawa')->willReturn($city);
        $places->expects(self::once())->method('categoriesBySlugs')->with(['parks'])->willReturn([$primary]);
        $places->expects(self::once())->method('amenitiesBySlugs')->with([])->willReturn([]);
        $places->expects(self::once())->method('save')->with($place, 1);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $handler = new PlaceCommandHandler($places, $transactions, $clock);

        $handler->update(new UpdatePlaceAggregate($place->id()->toRfc4122(), 1, 'After', 'after', 'Short', 'Description', 'Street 2', '00-002', 'warszawa', 'PL', 52.3, 21.1, 'Europe/Warsaw', true, false, false, VerificationStatusInput::UNVERIFIED, ['parks'], 'parks', [], [new AgeZoneInput('Children', 12, 72)], OpeningHoursModeInput::UNKNOWN, [], [], []));

        self::assertSame('After', $place->name());
        self::assertSame(1, $place->version());
    }
}
