<?php

declare(strict_types=1);

namespace App\Tests\Discovery\Infrastructure;

use App\Discovery\Application\PlaceSearchQuery;
use App\Discovery\Infrastructure\Doctrine\PlaceReadRepository;
use App\Places\Domain\OpeningScheduleEvaluator;
use App\Places\Domain\OpeningState;
use App\Places\Infrastructure\Doctrine\PlaceRepository as PlaceWriteRepository;
use App\Tests\Shared\Application\FrozenClock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlaceReadRepositoryPostgisTest extends KernelTestCase
{
    private const string PLACE_ID = '00000000-0000-7000-8000-000000000400';
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $connection->executeStatement('DELETE FROM special_opening_days WHERE place_id=:id', ['id' => self::PLACE_ID]);
        $connection->executeStatement('DELETE FROM weekly_opening_intervals WHERE place_id=:id', ['id' => self::PLACE_ID]);
        $connection->executeStatement("UPDATE places SET status='published',opening_hours_mode='scheduled',indoor=true,outdoor=false,free_entry=true WHERE id=:id", ['id' => self::PLACE_ID]);
    }

    public function testRadiusBoundaryCoordinateOrderAndGistIndex(): void
    {
        $repository = $this->repository('2026-07-20T08:00:00Z');
        $atPlace = $repository->search($this->query(latitude: 52.2297, longitude: 21.0122, radiusKm: 1.0, sort: 'distance'));
        self::assertSame(self::PLACE_ID, $atPlace['items'][0]->id);

        $boundary = $this->connection->fetchAssociative('SELECT ST_Y(point::geometry) latitude,ST_X(point::geometry) longitude FROM (SELECT ST_Project(location,1000,radians(90)) point FROM places WHERE id=:id) q', ['id' => self::PLACE_ID]);
        self::assertIsArray($boundary);
        $atBoundary = $repository->search($this->query(latitude: (float) $boundary['latitude'], longitude: (float) $boundary['longitude'], radiusKm: 1.0));
        self::assertTrue(array_any($atBoundary['items'], static fn ($item): bool => self::PLACE_ID === $item->id));
        $map = $repository->map(21.0, 52.2, 21.1, 52.3, $this->query());
        $feature = array_values(array_filter($map['features'], static fn ($item): bool => self::PLACE_ID === $item->id))[0];
        self::assertSame([21.0122, 52.2297], $feature->geometry['coordinates']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE tablename='places' AND indexname='idx_places_location' AND indexdef ILIKE '%gist%'"));
    }

    public function testAgeBoundariesOpenEndedAmenitiesAndTaxonomyFilters(): void
    {
        $this->connection->executeStatement('UPDATE place_age_zones SET min_age_months=12,max_age_months=NULL WHERE place_id=:id', ['id' => self::PLACE_ID]);
        $repository = $this->repository('2026-07-20T08:00:00Z');
        self::assertTrue($this->contains($repository, $this->query(ageMonths: 12)));
        self::assertTrue($this->contains($repository, $this->query(ageMonths: 216)));
        self::assertFalse($this->contains($repository, $this->query(ageMonths: 11)));
        self::assertTrue($this->contains($repository, $this->query(amenities: ['przewijak', 'toaleta-rodzinna'])));
        self::assertFalse($this->contains($repository, $this->query(amenities: ['przewijak', 'woda'])));
        self::assertTrue($this->contains($repository, $this->query(city: 'warszawa', category: 'bawialnie', indoor: true, outdoor: false, freeEntry: true)));
    }

    public function testPublicationPolicyBboxCountPaginationStableSortingAndInjection(): void
    {
        $repository = $this->repository('2026-07-20T08:00:00Z');
        self::assertTrue($this->contains($repository, $this->query()));
        $this->connection->executeStatement("UPDATE places SET status='draft' WHERE id=:id", ['id' => self::PLACE_ID]);
        self::assertFalse($this->contains($repository, $this->query()));
        $this->connection->executeStatement("UPDATE places SET status='temporarily_closed' WHERE id=:id", ['id' => self::PLACE_ID]);
        self::assertFalse($this->contains($repository, $this->query()));
        $this->connection->executeStatement("UPDATE places SET status='published' WHERE id=:id", ['id' => self::PLACE_ID]);
        self::assertTrue(array_any($repository->map(21.0, 52.2, 21.1, 52.3, $this->query())['features'], static fn ($feature): bool => self::PLACE_ID === $feature->id));
        self::assertFalse(array_any($repository->map(20.0, 51.0, 20.1, 51.1, $this->query())['features'], static fn ($feature): bool => self::PLACE_ID === $feature->id));

        $first = $repository->search($this->query(page: 1, pageSize: 2, sort: 'name'));
        $again = $repository->search($this->query(page: 1, pageSize: 2, sort: 'name'));
        self::assertGreaterThan(2, $first['total']);
        self::assertCount(2, $first['items']);
        self::assertSame(array_map(static fn ($item): string => $item->id, $first['items']), array_map(static fn ($item): string => $item->id, $again['items']));
        self::assertSame([], $repository->search($this->query(q: "' OR 1=1 --"))['items']);
    }

    public function testTextSearchCoversNameShortDescriptionAndDescriptionWithoutDatabaseLeakage(): void
    {
        $repository = $this->repository('2026-07-20T08:00:00Z');
        self::assertTrue($this->contains($repository, $this->query(q: 'Mokotów')));
        self::assertTrue($this->contains($repository, $this->query(q: 'jawnie opisanym')));
        self::assertTrue($this->contains($repository, $this->query(q: 'potwierdzić')));
        $details = $repository->details('demo-1-demo-bawialnia-mokotow');
        self::assertNotNull($details);
        $payload = $details->jsonSerialize();
        self::assertArrayNotHasKey('status', $payload);
        self::assertArrayNotHasKey('version', $payload);
        self::assertArrayNotHasKey('created_at', $payload);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE tablename='places' AND indexname='idx_places_search_document'"));
    }

    public function testRegularMultipleBreakAndWeeklyOvernightIntervals(): void
    {
        $this->weekly(1, 1, '09:00', '12:00', false);
        $this->weekly(1, 2, '13:00', '18:00', false);
        self::assertTrue($this->contains($this->repository('2026-07-20T08:00:00Z'), $this->query(openNow: true)));
        self::assertFalse($this->contains($this->repository('2026-07-20T10:30:00Z'), $this->query(openNow: true)));
        self::assertTrue($this->contains($this->repository('2026-07-20T12:00:00Z'), $this->query(openNow: true)));
        $this->connection->executeStatement('DELETE FROM weekly_opening_intervals WHERE place_id=:id', ['id' => self::PLACE_ID]);
        $this->weekly(1, 1, '20:00', '01:00', true);
        self::assertTrue($this->contains($this->repository('2026-07-20T20:30:00Z'), $this->query(openNow: true)));
        self::assertTrue($this->contains($this->repository('2026-07-20T22:30:00Z'), $this->query(openNow: true)));
        self::assertFalse($this->contains($this->repository('2026-07-21T01:30:00Z'), $this->query(openNow: true)));
    }

    public function testSpecialClosedOpenOvernightAndPreviousDayOvernightIntervals(): void
    {
        $this->weekly(2, 1, '00:01', '23:59', false);
        $dayId = $this->specialDay('2026-07-21', true);
        self::assertFalse($this->contains($this->repository('2026-07-21T10:00:00Z'), $this->query(openNow: true)));
        $this->connection->delete('special_opening_days', ['id' => $dayId]);
        $dayId = $this->specialDay('2026-07-21', false);
        $this->specialInterval($dayId, 1, '10:00', '12:00', false);
        self::assertTrue($this->contains($this->repository('2026-07-21T09:00:00Z'), $this->query(openNow: true)));
        $this->connection->delete('special_opening_days', ['id' => $dayId]);
        $this->connection->executeStatement('DELETE FROM weekly_opening_intervals WHERE place_id=:id', ['id' => self::PLACE_ID]);
        $dayId = $this->specialDay('2026-07-20', false);
        $this->specialInterval($dayId, 1, '20:00', '02:00', true);
        self::assertTrue($this->contains($this->repository('2026-07-20T23:00:00Z'), $this->query(openNow: true)));
        self::assertFalse($this->contains($this->repository('2026-07-21T01:00:00Z'), $this->query(openNow: true)));
    }

    public function testWarsawDstSpringAndAutumnTransitionsUseFrozenClock(): void
    {
        $this->weekly(7, 1, '03:00', '04:00', false);
        self::assertTrue($this->contains($this->repository('2026-03-29T01:30:00Z'), $this->query(openNow: true)));
        $this->connection->executeStatement('DELETE FROM weekly_opening_intervals WHERE place_id=:id', ['id' => self::PLACE_ID]);
        $this->weekly(7, 1, '02:00', '03:00', false);
        self::assertTrue($this->contains($this->repository('2026-10-25T00:30:00Z'), $this->query(openNow: true)));
        self::assertTrue($this->contains($this->repository('2026-10-25T01:30:00Z'), $this->query(openNow: true)));
    }

    public function testDomainEvaluatorAndPostgresqlUseTheSameTriStateScheduleSemantics(): void
    {
        $cases = [
            ['unknown', '2026-07-20T10:00:00Z', null, static function (): void {}],
            ['always_open', '2026-07-20T10:00:00Z', true, static function (): void {}],
            ['scheduled', '2026-07-20T10:00:00Z', true, fn () => $this->weekly(1, 1, '09:00', '18:00', false)],
            ['scheduled', '2026-07-20T16:00:00Z', false, fn () => $this->weekly(1, 1, '09:00', '18:00', false)],
            ['scheduled', '2026-07-20T22:30:00Z', true, fn () => $this->weekly(1, 1, '20:00', '01:00', true)],
            ['scheduled', '2026-07-20T10:00:00Z', false, fn () => $this->specialDay('2026-07-20', true)],
            ['scheduled', '2026-07-20T10:00:00Z', true, fn () => $this->specialDayWithMode('2026-07-20', 'open_24_hours')],
            ['scheduled', '2026-07-20T09:00:00Z', true, function (): void {
                $day = $this->specialDay('2026-07-20', false);
                $this->specialInterval($day, 1, '10:00', '12:00', false);
            }],
            ['scheduled', '2026-07-20T23:00:00Z', true, function (): void {
                $day = $this->specialDay('2026-07-20', false);
                $this->specialInterval($day, 1, '20:00', '02:00', true);
            }],
            ['scheduled', '2026-07-20T23:00:00Z', false, function (): void {
                $day = $this->specialDay('2026-07-20', false);
                $this->specialInterval($day, 1, '20:00', '02:00', true);
                $this->specialDay('2026-07-21', true);
            }],
            ['scheduled', '2026-10-25T00:30:00Z', true, fn () => $this->weekly(7, 1, '02:00', '03:00', false)],
            ['scheduled', '2026-10-25T01:30:00Z', true, fn () => $this->weekly(7, 1, '02:00', '03:00', false)],
            ['scheduled', '2026-10-25T00:30:00Z', true, function (): void {
                $day = $this->specialDay('2026-10-25', false);
                $this->specialInterval($day, 1, '02:00', '03:00', false);
            }],
            ['scheduled', '2026-10-25T01:30:00Z', true, function (): void {
                $day = $this->specialDay('2026-10-25', false);
                $this->specialInterval($day, 1, '02:00', '03:00', false);
            }],
        ];

        foreach ($cases as [$mode, $instant, $expected, $setup]) {
            $this->connection->executeStatement('DELETE FROM special_opening_days WHERE place_id=:id', ['id' => self::PLACE_ID]);
            $this->connection->executeStatement('DELETE FROM weekly_opening_intervals WHERE place_id=:id', ['id' => self::PLACE_ID]);
            $this->connection->update('places', ['opening_hours_mode' => $mode], ['id' => self::PLACE_ID]);
            $setup();
            $place = (new PlaceWriteRepository($this->connection))->get(self::PLACE_ID);
            $domain = (new OpeningScheduleEvaluator())->evaluate($place->openingHoursMode(), $place->timezone(), $place->weeklyOpeningHours(), $place->specialOpeningDays(), new \DateTimeImmutable($instant));
            $domainValue = match ($domain->state) {
                OpeningState::OPEN => true,
                OpeningState::CLOSED => false,
                OpeningState::UNKNOWN => null,
            };
            self::assertSame($expected, $domainValue, $mode.' at '.$instant);
            self::assertSame($domainValue, $this->sqlOpening($instant), $mode.' at '.$instant);
        }
    }

    private function repository(string $instant): PlaceReadRepository
    {
        $storage = $this->createMock(\App\Shared\Application\Storage\StorageInterface::class);

        return new PlaceReadRepository($this->connection, new FrozenClock(new \DateTimeImmutable($instant)), $storage);
    }

    private function contains(PlaceReadRepository $repository, PlaceSearchQuery $query): bool
    {
        return array_any($repository->search($query)['items'], static fn ($item): bool => self::PLACE_ID === $item->id);
    }

    /** @param list<string> $amenities */
    private function query(?string $city = null, ?string $category = null, ?int $ageMonths = null, ?float $latitude = null, ?float $longitude = null, ?float $radiusKm = null, array $amenities = [], ?bool $indoor = null, ?bool $outdoor = null, ?bool $freeEntry = null, bool $openNow = false, ?string $q = null, int $page = 1, int $pageSize = 20, string $sort = 'relevance'): PlaceSearchQuery
    {
        return new PlaceSearchQuery($city, $category, $ageMonths, $latitude, $longitude, $radiusKm, $amenities, $indoor, $outdoor, $freeEntry, $openNow, $q, $page, $pageSize, $sort);
    }

    private function weekly(int $weekday, int $sequence, string $opens, string $closes, bool $nextDay): void
    {
        $this->connection->insert('weekly_opening_intervals', ['id' => self::id(10 + $weekday * 3 + $sequence), 'place_id' => self::PLACE_ID, 'weekday' => $weekday, 'sequence' => $sequence, 'opens_at' => $opens, 'closes_at' => $closes, 'closes_next_day' => (int) $nextDay]);
    }

    private function specialDay(string $date, bool $closed): string
    {
        $id = self::id(100 + (int) str_replace('-', '', substr($date, 5)));
        $this->connection->insert('special_opening_days', ['id' => $id, 'place_id' => self::PLACE_ID, 'local_date' => $date, 'mode' => $closed ? 'closed' : 'custom', 'note' => 'test']);

        return $id;
    }

    private function specialDayWithMode(string $date, string $mode): string
    {
        $id = self::id(500 + (int) str_replace('-', '', substr($date, 5)));
        $this->connection->insert('special_opening_days', ['id' => $id, 'place_id' => self::PLACE_ID, 'local_date' => $date, 'mode' => $mode, 'note' => 'test']);

        return $id;
    }

    private function sqlOpening(string $instant): ?bool
    {
        foreach ($this->repository($instant)->search($this->query())['items'] as $item) {
            if (self::PLACE_ID === $item->id) {
                return $item->opening->isOpenNow;
            }
        }

        self::fail('Contract place must be returned by discovery.');
    }

    private function specialInterval(string $dayId, int $sequence, string $opens, string $closes, bool $nextDay): void
    {
        $this->connection->insert('special_opening_intervals', ['id' => self::id(900 + $sequence), 'special_opening_day_id' => $dayId, 'sequence' => $sequence, 'opens_at' => $opens, 'closes_at' => $closes, 'closes_next_day' => (int) $nextDay]);
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-7000-a000-%012d', $number);
    }
}
