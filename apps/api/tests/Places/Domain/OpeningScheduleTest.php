<?php

declare(strict_types=1);

namespace App\Tests\Places\Domain;

use App\Places\Domain\Category;
use App\Places\Domain\City;
use App\Places\Domain\OpeningHoursMode;
use App\Places\Domain\OpeningScheduleEvaluator;
use App\Places\Domain\OpeningState;
use App\Places\Domain\Place;
use App\Places\Domain\SpecialOpeningDay;
use App\Places\Domain\SpecialOpeningDayMode;
use App\Places\Domain\SpecialOpeningInterval;
use App\Places\Domain\ValueObject\Coordinates;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use App\Places\Domain\WeeklyOpeningInterval;
use PHPUnit\Framework\TestCase;

final class OpeningScheduleTest extends TestCase
{
    public function testAdjacentWeeklyIntervalsAreAllowedAndSequencesMustBeContiguous(): void
    {
        $place = $this->place();
        $place->replaceWeeklyOpeningHours([$this->weekly($place, 1, 1, '10:00', '12:00'), $this->weekly($place, 1, 2, '12:00', '14:00')], $place->updatedAt());
        self::assertCount(2, $place->weeklyOpeningHours());

        $this->expectException(\InvalidArgumentException::class);
        $place->replaceWeeklyOpeningHours([$this->weekly($place, 2, 2, '10:00', '12:00')], $place->updatedAt());
    }

    public function testWeeklyOverlapIsRejectedAcrossSameDayNextDayAndWeekWrap(): void
    {
        $cases = [
            fn (Place $place): array => [$this->weekly($place, 1, 1, '10:00', '13:00'), $this->weekly($place, 1, 2, '12:00', '14:00')],
            fn (Place $place): array => [$this->weekly($place, 1, 1, '20:00', '02:00', true), $this->weekly($place, 2, 1, '01:00', '03:00')],
            fn (Place $place): array => [$this->weekly($place, 7, 1, '20:00', '02:00', true), $this->weekly($place, 1, 1, '01:00', '03:00')],
            fn (Place $place): array => [$this->weekly($place, 1, 1, '20:00', '02:00', true), $this->weekly($place, 1, 2, '22:00', '23:00')],
        ];

        foreach ($cases as $case) {
            $place = $this->place();
            try {
                $place->replaceWeeklyOpeningHours($case($place), $place->updatedAt());
                self::fail('Overlapping normalized weekly ranges must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('overlap', $exception->getMessage());
            }
        }
    }

    public function testNextDayMarkerMustDescribeTheActualBoundary(): void
    {
        $place = $this->place();
        $this->expectException(\InvalidArgumentException::class);
        $this->weekly($place, 1, 1, '10:00', '18:00', true);
    }

    public function testSpecialModesAndCrossDateOverlapAreEnforced(): void
    {
        $place = $this->place();
        $closed = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-12-24'), SpecialOpeningDayMode::CLOSED);
        $open24 = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-12-25'), SpecialOpeningDayMode::OPEN_24_HOURS);
        $custom = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-12-26'), SpecialOpeningDayMode::CUSTOM);
        $custom->addInterval($this->special($custom, 1, '10:00', '12:00'));
        $place->replaceSpecialOpeningDays([$closed, $open24, $custom], $place->updatedAt());
        self::assertCount(3, $place->specialOpeningDays());

        $invalid = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-12-27'), SpecialOpeningDayMode::CUSTOM);
        try {
            $place->replaceSpecialOpeningDays([$invalid], $place->updatedAt());
            self::fail('A custom day without intervals must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $first = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-12-28'), SpecialOpeningDayMode::CUSTOM);
        $first->addInterval($this->special($first, 1, '20:00', '02:00', true));
        $second = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-12-29'), SpecialOpeningDayMode::CUSTOM);
        $second->addInterval($this->special($second, 1, '01:00', '03:00'));
        $this->expectException(\InvalidArgumentException::class);
        $place->replaceSpecialOpeningDays([$first, $second], $place->updatedAt());
    }

    public function testNonexistentWarsawDstWallTimeIsRejected(): void
    {
        $place = $this->place();
        $day = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-03-29'), SpecialOpeningDayMode::CUSTOM);
        $day->addInterval($this->special($day, 1, '02:30', '04:00'));

        $this->expectException(\InvalidArgumentException::class);
        $place->replaceSpecialOpeningDays([$day], $place->updatedAt());
    }

    public function testEvaluatorDistinguishesUnknownAlwaysOpenWeeklyAndSpecialOverrides(): void
    {
        $place = $this->place();
        $weekly = [$this->weekly($place, 1, 1, '09:00', '18:00')];
        $evaluator = new OpeningScheduleEvaluator();
        self::assertSame(OpeningState::UNKNOWN, $evaluator->evaluate(OpeningHoursMode::UNKNOWN, 'Europe/Warsaw', $weekly, [], new \DateTimeImmutable('2026-07-20T10:00:00Z'))->state);
        self::assertSame(OpeningState::OPEN, $evaluator->evaluate(OpeningHoursMode::ALWAYS_OPEN, 'Europe/Warsaw', [], [], new \DateTimeImmutable('2026-07-20T10:00:00Z'))->state);
        self::assertSame(OpeningState::OPEN, $evaluator->evaluate(OpeningHoursMode::SCHEDULED, 'Europe/Warsaw', $weekly, [], new \DateTimeImmutable('2026-07-20T10:00:00Z'))->state);

        $closed = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-07-20'), SpecialOpeningDayMode::CLOSED);
        self::assertSame(OpeningState::CLOSED, $evaluator->evaluate(OpeningHoursMode::ALWAYS_OPEN, 'Europe/Warsaw', [], [$closed], new \DateTimeImmutable('2026-07-20T10:00:00Z'))->state);
        $open24 = new SpecialOpeningDay($place, new \DateTimeImmutable('2026-07-20'), SpecialOpeningDayMode::OPEN_24_HOURS);
        self::assertSame(OpeningState::OPEN, $evaluator->evaluate(OpeningHoursMode::SCHEDULED, 'Europe/Warsaw', [], [$open24], new \DateTimeImmutable('2026-07-20T10:00:00Z'))->state);
    }

    private function place(): Place
    {
        $now = new \DateTimeImmutable('2026-07-16T10:00:00Z');
        $city = new City('Warszawa', 'warszawa', 'PL', new Coordinates(52.2, 21.0), 12, 15, 'Europe/Warsaw', true, $now);
        $category = new Category('Parks', 'parks', null, 'parks', true, 1);

        return new Place(new PlaceName('Place'), new PlaceSlug('place'), 'Short', 'Description', 'Street 1', '00-001', $city, 'PL', new Coordinates(52.2, 21.0), 'Europe/Warsaw', $category, true, false, false, $now);
    }

    private function weekly(Place $place, int $weekday, int $sequence, string $opens, string $closes, bool $nextDay = false): WeeklyOpeningInterval
    {
        return new WeeklyOpeningInterval($place, $weekday, $sequence, $this->time($opens), $this->time($closes), $nextDay);
    }

    private function special(SpecialOpeningDay $day, int $sequence, string $opens, string $closes, bool $nextDay = false): SpecialOpeningInterval
    {
        return new SpecialOpeningInterval($day, $sequence, $this->time($opens), $this->time($closes), $nextDay);
    }

    private function time(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('1970-01-01 '.$time);
    }
}
