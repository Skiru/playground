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
use PHPUnit\Framework\TestCase;

final class PublicationWorkflowTest extends TestCase
{
    public function testIncompletePlaceCannotBePublished(): void
    {
        $place = $this->place(false);
        $place->submitForReview(new \DateTimeImmutable('2026-07-16T08:00:00Z'));
        $this->expectException(\DomainException::class);
        $place->publish(new \DateTimeImmutable('2026-07-16T09:00:00Z'));
    }

    public function testCompletePlaceCanBePublished(): void
    {
        $place = $this->place(true);
        $place->submitForReview(new \DateTimeImmutable('2026-07-16T08:00:00Z'));
        $place->publish(new \DateTimeImmutable('2026-07-16T09:00:00Z'));
        self::assertSame(PlaceStatus::PUBLISHED, $place->status());
    }

    private function place(bool $complete): Place
    {
        $now = new \DateTimeImmutable('2026-07-16T08:00:00Z');
        $category = new Category('Bawialnie', 'bawialnie', null, 'play', true, 1);
        $city = new City('Warszawa', 'warszawa', 'PL', new Coordinates(52.2297, 21.0122), 12, 15, 'Europe/Warsaw', true, $now);
        $place = new Place(new PlaceName('Demo Bawialnia'), new PlaceSlug('demo-bawialnia'), 'Krótki opis', 'Pełny opis', 'Demo 1', '00-001', $city, 'PL', new Coordinates(52.23, 21.01), 'Europe/Warsaw', $category, true, false, false, $now);
        if ($complete) {
            $place->addAgeZone(new PlaceAgeZone($place, 'Maluchy', new AgeRange(12, 72), null, 'admin'));
        }

        return $place;
    }
}
