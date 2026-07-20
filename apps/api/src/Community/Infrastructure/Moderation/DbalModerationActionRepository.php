<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Moderation;

use App\Community\Domain\Moderation\ModerationActionRecord;
use App\Community\Domain\Moderation\ModerationActionRepository;
use App\Community\Domain\Moderation\ModerationActionType;
use App\Community\Domain\Moderation\TargetType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalModerationActionRepository implements ModerationActionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(Uuid $id): ?ModerationActionRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM moderation_actions WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    /**
     * @return list<ModerationActionRecord>
     */
    public function findByTarget(Uuid $targetId, TargetType $targetType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM moderation_actions WHERE target_id = :target_id AND target_type = :target_type ORDER BY created_at DESC',
            [
                'target_id' => $targetId->toRfc4122(),
                'target_type' => $targetType->value,
            ]
        );

        return array_map([$this, 'reconstitute'], $rows);
    }

    public function save(ModerationActionRecord $record): void
    {
        $id = $record->id()->toRfc4122();
        $moderatorId = $record->moderatorId()->toRfc4122();
        $targetType = $record->targetType()->value;
        $targetId = $record->targetId()->toRfc4122();
        $action = $record->action()->value;
        $reason = $record->reason();
        $createdAt = $record->createdAt()->format('Y-m-d H:i:s');
        $previousStatus = $record->previousStatus();
        $resultingStatus = $record->resultingStatus();
        $reportId = $record->reportId()?->toRfc4122();
        $correlationId = $record->correlationId();

        $this->connection->executeStatement(
            'INSERT INTO moderation_actions (id, moderator_id, target_type, target_id, action, reason, created_at, previous_status, resulting_status, report_id, correlation_id) 
             VALUES (:id, :moderator_id, :target_type, :target_id, :action, :reason, :created_at, :previous_status, :resulting_status, :report_id, :correlation_id)',
            [
                'id' => $id,
                'moderator_id' => $moderatorId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'action' => $action,
                'reason' => $reason,
                'created_at' => $createdAt,
                'previous_status' => $previousStatus,
                'resulting_status' => $resultingStatus,
                'report_id' => $reportId,
                'correlation_id' => $correlationId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reconstitute(array $row): ModerationActionRecord
    {
        return new ModerationActionRecord(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['moderator_id']),
            TargetType::from((string) $row['target_type']),
            Uuid::fromString((string) $row['target_id']),
            ModerationActionType::from((string) $row['action']),
            (string) $row['reason'],
            new \DateTimeImmutable((string) $row['created_at']),
            null !== $row['previous_status'] ? (string) $row['previous_status'] : null,
            (string) $row['resulting_status'],
            null === $row['report_id'] ? null : Uuid::fromString((string) $row['report_id']),
            null === $row['correlation_id'] ? null : (string) $row['correlation_id']
        );
    }
}
