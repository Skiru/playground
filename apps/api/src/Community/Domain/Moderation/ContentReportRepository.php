<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

use Symfony\Component\Uid\Uuid;

interface ContentReportRepository
{
    public function findById(Uuid $id): ?ContentReport;

    public function findOpenByReporterAndTarget(Uuid $reporterId, Uuid $targetId, TargetType $targetType): ?ContentReport;

    /** @return list<ContentReport> */
    public function findOpenReportsForTarget(Uuid $targetId, TargetType $targetType): array;

    public function save(ContentReport $report): void;
}
