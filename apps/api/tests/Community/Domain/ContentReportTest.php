<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\Moderation\ContentReport;
use App\Community\Domain\Moderation\ReportReason;
use App\Community\Domain\Moderation\ReportStatus;
use App\Community\Domain\Moderation\TargetType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ContentReportTest extends TestCase
{
    private function createOpenReport(): ContentReport
    {
        return new ContentReport(
            Uuid::v7(),
            Uuid::v7(),
            TargetType::REVIEW,
            Uuid::v7(),
            ReportReason::SPAM,
            'Test report',
            ReportStatus::OPEN,
            new \DateTimeImmutable('2026-08-19 12:00:00')
        );
    }

    public function testClaimOpenReport(): void
    {
        $report = $this->createOpenReport();
        $modId = Uuid::v7();
        $now = new \DateTimeImmutable('2026-08-19 12:05:00');

        $report->claim($modId, $now);

        $this->assertSame(ReportStatus::IN_REVIEW, $report->status());
        $this->assertTrue($report->claimedBy()?->equals($modId));
        $this->assertEquals($now, $report->claimedAt());
        $this->assertFalse($report->isClaimExpired($now));
    }

    public function testSecondModeratorCannotClaimActiveCase(): void
    {
        $report = $this->createOpenReport();
        $modA = Uuid::v7();
        $modB = Uuid::v7();
        $t1 = new \DateTimeImmutable('2026-08-19 12:00:00');
        $t2 = new \DateTimeImmutable('2026-08-19 12:05:00');

        $report->claim($modA, $t1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Case is currently claimed by another moderator.');

        $report->claim($modB, $t2);
    }

    public function testSameModeratorCanContinueActiveClaim(): void
    {
        $report = $this->createOpenReport();
        $mod = Uuid::v7();
        $t1 = new \DateTimeImmutable('2026-08-19 12:00:00');
        $t2 = new \DateTimeImmutable('2026-08-19 12:05:00');

        $report->claim($mod, $t1);
        $report->claim($mod, $t2);

        $this->assertSame(ReportStatus::IN_REVIEW, $report->status());
        $this->assertTrue($report->claimedBy()?->equals($mod));
        $this->assertEquals($t2, $report->claimedAt());
    }

    public function testExpiredClaimCanBeRecoveredByNewModerator(): void
    {
        $report = $this->createOpenReport();
        $modA = Uuid::v7();
        $modB = Uuid::v7();
        $t1 = new \DateTimeImmutable('2026-08-19 12:00:00');
        // Lease duration is 900 seconds (15 minutes). 12:20 is 20 minutes later (expired).
        $t2 = new \DateTimeImmutable('2026-08-19 12:20:00');

        $report->claim($modA, $t1);
        $this->assertTrue($report->isClaimExpired($t2));

        $report->claim($modB, $t2);

        $this->assertSame(ReportStatus::IN_REVIEW, $report->status());
        $this->assertTrue($report->claimedBy()?->equals($modB));
        $this->assertEquals($t2, $report->claimedAt());
        $this->assertFalse($report->isClaimExpired($t2));
    }

    public function testResolvedCaseHasNoClaimOwner(): void
    {
        $report = $this->createOpenReport();
        $mod = Uuid::v7();
        $now = new \DateTimeImmutable('2026-08-19 12:10:00');

        $report->claim($mod, $now);
        $report->resolve($mod, $now);

        $this->assertSame(ReportStatus::RESOLVED, $report->status());
        $this->assertNull($report->claimedBy());
        $this->assertNull($report->claimedAt());
        $this->assertTrue($report->resolvedBy()?->equals($mod));
        $this->assertEquals($now, $report->resolvedAt());
    }

    public function testDismissedCaseHasNoClaimOwner(): void
    {
        $report = $this->createOpenReport();
        $mod = Uuid::v7();
        $now = new \DateTimeImmutable('2026-08-19 12:10:00');

        $report->claim($mod, $now);
        $report->dismiss($mod, $now);

        $this->assertSame(ReportStatus::DISMISSED, $report->status());
        $this->assertNull($report->claimedBy());
        $this->assertNull($report->claimedAt());
        $this->assertTrue($report->resolvedBy()?->equals($mod));
        $this->assertEquals($now, $report->resolvedAt());
    }
}
