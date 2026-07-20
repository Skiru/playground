<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Moderation;

use App\Community\Domain\Moderation\ContentReport;
use App\Community\Domain\Moderation\ContentReportRepository;
use App\Community\Domain\Moderation\ReportReason;
use App\Community\Domain\Moderation\ReportStatus;
use App\Community\Domain\Moderation\TargetType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalContentReportRepository implements ContentReportRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(Uuid $id): ?ContentReport
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM content_reports WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findOpenByReporterAndTarget(Uuid $reporterId, Uuid $targetId, TargetType $targetType): ?ContentReport
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM content_reports WHERE reporter_id = :reporter_id AND target_id = :target_id AND target_type = :target_type AND status = \'OPEN\' LIMIT 1',
            [
                'reporter_id' => $reporterId->toRfc4122(),
                'target_id' => $targetId->toRfc4122(),
                'target_type' => $targetType->value,
            ]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    /**
     * @return list<ContentReport>
     */
    public function findOpenReportsForTarget(Uuid $targetId, TargetType $targetType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM content_reports WHERE target_id = :target_id AND target_type = :target_type AND status = \'OPEN\' ORDER BY created_at ASC',
            [
                'target_id' => $targetId->toRfc4122(),
                'target_type' => $targetType->value,
            ]
        );

        return array_map([$this, 'reconstitute'], $rows);
    }

    public function save(ContentReport $report): void
    {
        $id = $report->id()->toRfc4122();
        $reporterId = $report->reporterId()->toRfc4122();
        $targetType = $report->targetType()->value;
        $targetId = $report->targetId()->toRfc4122();
        $reason = $report->reason()->value;
        $details = $report->details();
        $status = $report->status()->value;
        $createdAt = $report->createdAt()->format('Y-m-d H:i:s');
        $resolvedAt = $report->resolvedAt()?->format('Y-m-d H:i:s');
        $resolvedBy = $report->resolvedBy()?->toRfc4122();

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM content_reports WHERE id = :id',
            ['id' => $id]
        );

        if ($exists) {
            $this->connection->executeStatement(
                'UPDATE content_reports SET 
                    status = :status,
                    resolved_at = :resolved_at,
                    resolved_by = :resolved_by
                 WHERE id = :id',
                [
                    'id' => $id,
                    'status' => $status,
                    'resolved_at' => $resolvedAt,
                    'resolved_by' => $resolvedBy,
                ]
            );
        } else {
            $this->connection->executeStatement(
                'INSERT INTO content_reports (id, reporter_id, target_type, target_id, reason, details, status, created_at, resolved_at, resolved_by) 
                 VALUES (:id, :reporter_id, :target_type, :target_id, :reason, :details, :status, :created_at, :resolved_at, :resolved_by)',
                [
                    'id' => $id,
                    'reporter_id' => $reporterId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'reason' => $reason,
                    'details' => $details,
                    'status' => $status,
                    'created_at' => $createdAt,
                    'resolved_at' => $resolvedAt,
                    'resolved_by' => $resolvedBy,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reconstitute(array $row): ContentReport
    {
        return new ContentReport(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['reporter_id']),
            TargetType::from((string) $row['target_type']),
            Uuid::fromString((string) $row['target_id']),
            ReportReason::from((string) $row['reason']),
            null !== $row['details'] ? (string) $row['details'] : null,
            ReportStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['created_at']),
            null === $row['resolved_at'] ? null : new \DateTimeImmutable((string) $row['resolved_at']),
            null === $row['resolved_by'] ? null : Uuid::fromString((string) $row['resolved_by'])
        );
    }
}
