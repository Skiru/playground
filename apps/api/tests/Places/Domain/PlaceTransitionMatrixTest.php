<?php

declare(strict_types=1);

namespace App\Tests\Places\Domain;

use App\Places\Domain\Category;
use App\Places\Domain\City;
use App\Places\Domain\Place;
use App\Places\Domain\PlaceAgeZone;
use App\Places\Domain\PlaceStatus;
use App\Places\Domain\ValueObject\AgeRange;
use App\Places\Domain\ValueObject\Coordinates;
use App\Places\Domain\ValueObject\PlaceName;
use App\Places\Domain\ValueObject\PlaceSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlaceTransitionMatrixTest extends TestCase
{
    /** @return iterable<string, array{list<string>, PlaceStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'draft to pending' => [['submit'], PlaceStatus::PENDING_REVIEW];
        yield 'pending to published' => [['submit', 'publish'], PlaceStatus::PUBLISHED];
        yield 'published to draft' => [['submit', 'publish', 'unpublish'], PlaceStatus::DRAFT];
        yield 'published to reverify' => [['submit', 'publish', 'reverify'], PlaceStatus::NEEDS_REVERIFICATION];
        yield 'reverified to published' => [['submit', 'publish', 'reverify', 'publish'], PlaceStatus::PUBLISHED];
        yield 'published to temporary closed' => [['submit', 'publish', 'close'], PlaceStatus::TEMPORARILY_CLOSED];
        yield 'temporary closed to published' => [['submit', 'publish', 'close', 'reopen'], PlaceStatus::PUBLISHED];
        yield 'draft to archived' => [['archive'], PlaceStatus::ARCHIVED];
        yield 'pending to archived' => [['submit', 'archive'], PlaceStatus::ARCHIVED];
        yield 'published to archived' => [['submit', 'publish', 'archive'], PlaceStatus::ARCHIVED];
    }

    /** @param list<string> $actions */
    #[DataProvider('allowedTransitions')]
    public function testAllowedTransitions(array $actions, PlaceStatus $expected): void
    {
        $place = $this->place();
        foreach ($actions as $action) {
            $this->transition($place, $action);
        }
        self::assertSame($expected, $place->status());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function forbiddenTransitions(): iterable
    {
        yield 'draft publish' => [[], 'publish'];
        yield 'draft unpublish' => [[], 'unpublish'];
        yield 'draft close' => [[], 'close'];
        yield 'pending submit' => [['submit'], 'submit'];
        yield 'pending unpublish' => [['submit'], 'unpublish'];
        yield 'published submit' => [['submit', 'publish'], 'submit'];
        yield 'temporary close twice' => [['submit', 'publish', 'close'], 'close'];
        yield 'archived archive' => [['archive'], 'archive'];
        yield 'archived reopen' => [['archive'], 'reopen'];
    }

    /** @param list<string> $setup */
    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitions(array $setup, string $forbidden): void
    {
        $place = $this->place();
        foreach ($setup as $action) {
            $this->transition($place, $action);
        }
        $this->expectException(\DomainException::class);
        $this->transition($place, $forbidden);
    }

    private function transition(Place $place, string $action): void
    {
        $now = new \DateTimeImmutable('2026-07-16T10:00:00Z');
        match ($action) {
            'submit' => $place->submitForReview($now),
            'publish' => $place->publish($now),
            'unpublish' => $place->unpublish($now),
            'reverify' => $place->markNeedsReverification($now),
            'close' => $place->markTemporarilyClosed($now),
            'reopen' => $place->reopen($now),
            'archive' => $place->archive($now),
            default => throw new \LogicException('Unknown transition.'),
        };
    }

    private function place(): Place
    {
        $now = new \DateTimeImmutable('2026-07-16T08:00:00Z');
        $category = new Category('Bawialnie', 'bawialnie', null, 'play', true, 1);
        $city = new City('Warszawa', 'warszawa', 'PL', new Coordinates(52.2297, 21.0122), 12, 15, 'Europe/Warsaw', true, $now);
        $place = new Place(new PlaceName('Demo Bawialnia'), new PlaceSlug('demo-bawialnia'), 'Krótki opis', 'Pełny opis', 'Demo 1', '00-001', $city, 'PL', new Coordinates(52.23, 21.01), 'Europe/Warsaw', $category, true, false, false, $now);
        $place->addAgeZone(new PlaceAgeZone($place, 'Maluchy', new AgeRange(12, 72), null, 'admin'));

        return $place;
    }
}
