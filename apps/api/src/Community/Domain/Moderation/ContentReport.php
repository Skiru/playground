<?php

declare(strict_types=1);

namespace App\Community\Domain\Moderation;

use Symfony\Component\Uid\Uuid;

final class ContentReport
{
    public const CLAIM_LEASE_SECONDS = 900;

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
    private ?Uuid $claimedBy;
    private ?\DateTimeImmutable $claimedAt;

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
        ?Uuid $claimedBy = null,
        ?\DateTimeImmutable $claimedAt = null,
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
        $this->claimedBy = $claimedBy;
        $this->claimedAt = $claimedAt;
    }

    public function isClaimExpired(\DateTimeImmutable $now): bool
    {
        if (ReportStatus::IN_REVIEW !== $this->status) {
            return false;
        }

        if (null === $this->claimedAt) {
            return true;
        }

        return ($now->getTimestamp() - $this->claimedAt->getTimestamp()) >= self::CLAIM_LEASE_SECONDS;
    }

    public function claim(Uuid $moderatorId, \DateTimeImmutable $now): void
    {
        if (ReportStatus::IN_REVIEW === $this->status) {
            if (null !== $this->claimedBy && $this->claimedBy->equals($moderatorId) && !$this->isClaimExpired($now)) {
                $this->claimedAt = $now;

                return;
            }

            if ($this->isClaimExpired($now)) {
                $this->claimedBy = $moderatorId;
                $this->claimedAt = $now;

                return;
            }

            throw new \LogicException('Case is currently claimed by another moderator.');
        }

        if (ReportStatus::OPEN !== $this->status) {
            throw new \LogicException('Only OPEN or expired IN_REVIEW reports can be claimed for review.');
        }

        $this->status = ReportStatus::IN_REVIEW;
        $this->claimedBy = $moderatorId;
        $this->claimedAt = $now;
    }

    public function resolve(Uuid $moderatorId, \DateTimeImmutable $now): void
    {
        if (ReportStatus::DISMISSED === $this->status) {
            throw new \LogicException('Dismissed report cannot be resolved.');
        }
        $this->status = ReportStatus::RESOLVED;
        $this->resolvedAt = $now;
        $this->resolvedBy = $moderatorId;
        $this->claimedBy = null;
        $this->claimedAt = null;
    }

    public function dismiss(Uuid $moderatorId, \DateTimeImmutable $now): void
    {
        if (ReportStatus::RESOLVED === $this->status) {
            throw new \LogicException('Resolved report cannot be dismissed.');
        }
        $this->status = ReportStatus::DISMISSED;
        $this->resolvedAt = $now;
        $this->resolvedBy = $moderatorId;
        $this->claimedBy = null;
        $this->claimedAt = null;
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

    public function claimedBy(): ?Uuid
    {
        return $this->claimedBy;
    }

    public function claimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }
}
