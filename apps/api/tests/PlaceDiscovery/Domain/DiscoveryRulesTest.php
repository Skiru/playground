<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Domain;

use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;
use App\PlaceDiscovery\Domain\Aggregate\DiscoveryArea;
use App\PlaceDiscovery\Domain\DuplicateDetector;
use App\PlaceDiscovery\Domain\FamilyDiscoveryProfile;
use App\PlaceDiscovery\Domain\OvertureOperatingStatus;
use App\PlaceDiscovery\Domain\PlaceNormalizer;
use App\PlaceDiscovery\Domain\ProviderPlace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryRulesTest extends TestCase
{
    public function testAreaComputesBoundedBoxAndRejectsHugeRadius(): void
    {
        $area = new DiscoveryArea('id', 'Rzeszów', false, 'PL', 50.0413, 21.999, 3, 0.8, 20);
        [$west, $south, $east, $north] = $area->boundingBox();
        self::assertLessThan(21.999, $west);
        self::assertLessThan(50.0413, $south);
        self::assertGreaterThan(21.999, $east);
        self::assertGreaterThan(50.0413, $north);
        $this->expectException(\DomainException::class);
        new DiscoveryArea('id', 'Polska', false, 'PL', 52, 19, 500, 0.8, 20);
    }

    #[DataProvider('names')]
    public function testUnicodeNormalization(string $raw, string $expected): void
    {
        $normalizer = new PlaceNormalizer();
        self::assertSame($expected, $normalizer->comparison($raw));
    }

    public static function names(): iterable
    {
        yield ['  Bawialnia   Dziecięca ', 'bawialnia dziecieca'];
        yield ['ŻÓŁĆ Junior-Play', 'zołc junior play'];
        yield ["Rodzinny\u{00A0}Park", 'rodzinny park'];
    }

    public function testExactFamilyCategoryBecomesPending(): void
    {
        $place = $this->place('Park zabaw dla dzieci', 'playground');
        $normalized = (new PlaceNormalizer())->normalize($place);
        $classification = (new FamilyDiscoveryProfile())->classify($place, $normalized);
        self::assertSame(CandidateStatus::PENDING, $classification->status);
        self::assertSame('bawialnie', $classification->category);
        self::assertContains('family_category:playground', $classification->reasons);
    }

    public function testBroadCafeWithoutFamilySignalNeedsMapping(): void
    {
        $place = $this->place('Kawiarnia Centrum', 'cafe');
        $classification = (new FamilyDiscoveryProfile())->classify($place, (new PlaceNormalizer())->normalize($place));
        self::assertSame(CandidateStatus::NEEDS_MAPPING, $classification->status);
        self::assertContains('broad_category_without_family_signal', $classification->reasons);
        self::assertFalse($classification->discoverable);
    }

    public function testBroadCafeWithFamilySignalIsReviewableButUnmapped(): void
    {
        $place = $this->place('Family Cafe', 'cafe');
        $classification = (new FamilyDiscoveryProfile())->classify($place, (new PlaceNormalizer())->normalize($place));
        self::assertSame(CandidateStatus::NEEDS_MAPPING, $classification->status);
        self::assertTrue($classification->discoverable);
        self::assertContains('family_keyword:family', $classification->reasons);
    }

    #[DataProvider('operatingStatuses')]
    public function testCanonicalOperatingStatusClassification(?string $status, CandidateStatus $expected): void
    {
        $place = $this->place('Park zabaw dla dzieci', 'playground', $status);
        $classification = (new FamilyDiscoveryProfile())->classify($place, (new PlaceNormalizer())->normalize($place));
        self::assertSame($expected, $classification->status);
    }

    public static function operatingStatuses(): iterable
    {
        yield 'provider open state' => [OvertureOperatingStatus::OPEN->value, CandidateStatus::PENDING];
        yield 'temporarily closed' => [OvertureOperatingStatus::TEMPORARILY_CLOSED->value, CandidateStatus::PENDING];
        yield 'permanently closed' => [OvertureOperatingStatus::PERMANENTLY_CLOSED->value, CandidateStatus::STALE];
        yield 'null is unknown, not open' => [null, CandidateStatus::PENDING];
        yield 'future value is safe, not permanently closed' => ['future_provider_status', CandidateStatus::PENDING];
    }

    public function testNearbyDistanceIsExactEnough(): void
    {
        $distance = (new DuplicateDetector())->haversineMetres(50.0413, 21.999, 50.0422, 21.999);
        self::assertGreaterThan(95, $distance);
        self::assertLessThan(105, $distance);
    }

    private function place(string $name, string $category, ?string $operatingStatus = OvertureOperatingStatus::OPEN->value): ProviderPlace
    {
        return new ProviderPlace('gers', '2026-07-22.0', '1', $name, 50.0413, 21.999, 'Rynek 1', '35-001', 'Rzeszów', 'PL', 'https://www.example.pl/path', '+48 123 456 789', [$category], $category, 0.9, $operatingStatus, ['id' => 'gers', 'taxonomy' => ['hierarchy' => [$category]]]);
    }
}
