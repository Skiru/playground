<?php

declare(strict_types=1);

namespace App\Tests\PlaceDiscovery\Domain;

use App\PlaceDiscovery\Domain\Aggregate\CandidateStatus;
use App\PlaceDiscovery\Domain\Aggregate\PlaceCandidate;
use PHPUnit\Framework\TestCase;

final class PlaceCandidateTest extends TestCase
{
    public function testApprovalIsOneWayAndCannotBeRepeated(): void
    {
        $candidate = new PlaceCandidate('candidate', 'overture', 'gers-id', 'Rodzinny park', 50.04, 22.0, 'parki');
        $candidate->approve('place');
        self::assertSame(CandidateStatus::APPROVED, $candidate->status());
        self::assertSame('place', $candidate->approvedPlaceId());
        $this->expectException(\DomainException::class);
        $candidate->approve('place-2');
    }

    public function testManualEditIsPreservedOnRefresh(): void
    {
        $candidate = new PlaceCandidate('candidate', 'overture', 'gers-id', 'Park', 50.04, 22.0, 'parki');
        $candidate->edit('Park poprawiony', 50.05, 22.01, 'parki');
        $candidate->refresh('Nazwa źródłowa', 1, 2, 'sport');
        self::assertSame('Park poprawiony', $candidate->name());
        self::assertTrue($candidate->hasNewerSourceData());
    }

    public function testNeedsMappingCanBeMappedButNotApprovedDirectly(): void
    {
        $candidate = new PlaceCandidate('candidate', 'overture', 'gers-id', 'Atrakcja', 50.04, 22.0, null, CandidateStatus::NEEDS_MAPPING);
        try {
            $candidate->approve('place');
            self::fail('Approval should fail.');
        } catch (\DomainException) {
            self::assertSame(CandidateStatus::NEEDS_MAPPING, $candidate->status());
        }
        $candidate->mapCategory('muzea');
        self::assertSame(CandidateStatus::PENDING, $candidate->status());
    }

    public function testRejectionRequiresReason(): void
    {
        $candidate = new PlaceCandidate('candidate', 'overture', 'gers-id', 'Park', 50.04, 22.0, 'parki');
        $this->expectException(\DomainException::class);
        $candidate->reject(' ');
    }
}
