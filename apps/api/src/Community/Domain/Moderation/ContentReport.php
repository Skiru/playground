<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

use Symfony\Component\Uid\Uuid;

final class ContentReport
{
    private Uuid $id;
    private Uuid $reporterId;
    private TargetType $targetType;
    private Uuid $targetId;
    private ReportReason $reason;
    private ?string $details;
    private ReportStatus $status;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $resolvedAt;
    private ?Uuid $resolvedBy;

    public function __construct(
        Uuid $id,
        Uuid $reporterId,
        TargetType $targetType,
        Uuid $targetId,
        ReportReason $reason,
        ?string $details,
        ReportStatus $status,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $resolvedAt = null,
        ?Uuid $resolvedBy = null,
    ) {
        $this->id = $id;
        $this->reporterId = $reporterId;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->reason = $reason;
        $this->details = $details;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->resolvedAt = $resolvedAt;
        $this->resolvedBy = $resolvedBy;
    }

    public function resolve(Uuid $moderatorId, \DateTimeImmutable $now): void
    {
        $this->status = ReportStatus::RESOLVED;
        $this->resolvedAt = $now;
        $this->resolvedBy = $moderatorId;
    }

    public function dismiss(Uuid $moderatorId, \DateTimeImmutable $now): void
    {
        $this->status = ReportStatus::DISMISSED;
        $this->resolvedAt = $now;
        $this->resolvedBy = $moderatorId;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function reporterId(): Uuid
    {
        return $this->reporterId;
    }

    public function targetType(): TargetType
    {
        return $this->targetType;
    }

    public function targetId(): Uuid
    {
        return $this->targetId;
    }

    public function reason(): ReportReason
    {
        return $this->reason;
    }

    public function details(): ?string
    {
        return $this->details;
    }

    public function status(): ReportStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function resolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolvedBy(): ?Uuid
    {
        return $this->resolvedBy;
    }
}
