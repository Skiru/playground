<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CandidateAuditTrail
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param list<string> $changedFields */
    public function append(string $candidateId, string $actorType, string $action, ?string $previousStatus, ?string $nextStatus, array $changedFields = [], ?string $reason = null, ?string $runId = null, ?string $actorId = null, ?string $sourceRelease = null, ?string $correlationId = null): void
    {
        $fields = array_values(array_unique(\array_slice(array_filter($changedFields, static fn (mixed $field): bool => \is_string($field) && preg_match('/^[a-z0-9_]{1,64}$/', $field)), 0, 64)));
        $this->connection->insert('place_candidate_audit_events', [
            'id' => Uuid::v7()->toRfc4122(),
            'candidate_id' => $candidateId,
            'discovery_run_id' => $runId,
            'actor_type' => $actorType,
            'actor_id' => null === $actorId ? null : mb_substr($actorId, 0, 160),
            'action' => mb_substr($action, 0, 48),
            'previous_status' => $previousStatus,
            'next_status' => $nextStatus,
            'changed_fields' => json_encode($fields, \JSON_THROW_ON_ERROR),
            'reason' => null === $reason ? null : mb_substr($reason, 0, 1000),
            'source_release' => $sourceRelease,
            'correlation_id' => null === $correlationId ? null : mb_substr($correlationId, 0, 160),
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
