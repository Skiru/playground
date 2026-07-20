<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

use Symfony\Component\Uid\Uuid;

final class ModerationActionRecord
{
    private Uuid $id;
    private Uuid $moderatorId;
    private TargetType $targetType;
    private Uuid $targetId;
    private ModerationActionType $action;
    private string $reason;
    private \DateTimeImmutable $createdAt;
    private ?string $previousStatus;
    private string $resultingStatus;

    public function __construct(
        Uuid $id,
        Uuid $moderatorId,
        TargetType $targetType,
        Uuid $targetId,
        ModerationActionType $action,
        string $reason,
        \DateTimeImmutable $createdAt,
        ?string $previousStatus,
        string $resultingStatus,
    ) {
        $trimmedReason = trim($reason);
        if (empty($trimmedReason)) {
            throw new \InvalidArgumentException('Moderation reason cannot be empty.');
        }

        $this->id = $id;
        $this->moderatorId = $moderatorId;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->action = $action;
        $this->reason = $trimmedReason;
        $this->createdAt = $createdAt;
        $this->previousStatus = $previousStatus;
        $this->resultingStatus = $resultingStatus;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function moderatorId(): Uuid
    {
        return $this->moderatorId;
    }

    public function targetType(): TargetType
    {
        return $this->targetType;
    }

    public function targetId(): Uuid
    {
        return $this->targetId;
    }

    public function action(): ModerationActionType
    {
        return $this->action;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function previousStatus(): ?string
    {
        return $this->previousStatus;
    }

    public function resultingStatus(): string
    {
        return $this->resultingStatus;
    }
}
