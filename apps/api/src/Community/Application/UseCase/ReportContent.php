<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use App\Community\Domain\Moderation\ContentReport;
use App\Community\Domain\Moderation\ContentReportRepository;
use App\Community\Domain\Moderation\ReportReason;
use App\Community\Domain\Moderation\ReportStatus;
use App\Community\Domain\Moderation\TargetType;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class ReportContent
{
    public function __construct(
        private readonly ContentReportRepository $reportRepository,
        private readonly ReviewRepository $reviewRepository,
        private readonly PlaceCommentRepository $commentRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $reporterId, Uuid $targetId, TargetType $targetType, ReportReason $reason, ?string $details): ContentReport
    {
        // 1. Validate target exists, matches declared type, and is visible
        switch ($targetType) {
            case TargetType::REVIEW:
                $target = $this->reviewRepository->findById($targetId);
                if (null === $target || ReviewStatus::HIDDEN === $target->status() || ReviewStatus::REMOVED_BY_MODERATOR === $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;

            case TargetType::PLACE_COMMENT:
                $target = $this->commentRepository->findById($targetId);
                if (null === $target || PlaceCommentStatus::HIDDEN === $target->status() || PlaceCommentStatus::REMOVED_BY_MODERATOR === $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;

            case TargetType::FORUM_THREAD:
                $target = $this->threadRepository->findById($targetId);
                if (null === $target || ForumThreadStatus::HIDDEN === $target->status() || ForumThreadStatus::REMOVED_BY_MODERATOR === $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;

            case TargetType::FORUM_POST:
                $target = $this->postRepository->findById($targetId);
                if (null === $target || ForumPostStatus::HIDDEN === $target->status() || ForumPostStatus::REMOVED_BY_MODERATOR === $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;
        }

        // 2. Prevent duplicate open reports by the same reporter for the same target
        $existing = $this->reportRepository->findOpenByReporterAndTarget($reporterId, $targetId, $targetType);
        if (null !== $existing) {
            throw new ApiException(409, 'You have already reported this content.', 'DUPLICATE_REPORT');
        }

        $now = $this->clock->now();
        $report = new ContentReport(
            Uuid::v7(),
            $reporterId,
            $targetType,
            $targetId,
            $reason,
            $details,
            ReportStatus::OPEN,
            $now
        );

        $this->reportRepository->save($report);

        return $report;
    }
}
