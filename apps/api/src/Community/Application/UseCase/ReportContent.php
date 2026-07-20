<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublishedPlaceLookup;
use App\Community\Domain\Forum\ForumCategoryRepository;
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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

final class ReportContent
{
    public function __construct(
        private readonly ContentReportRepository $reportRepository,
        private readonly ReviewRepository $reviewRepository,
        private readonly PlaceCommentRepository $commentRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly PublishedPlaceLookup $placeLookup,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $reporterId, Uuid $targetId, TargetType $targetType, ReportReason $reason, ?string $details): ContentReport
    {
        // 1. Validate target exists, matches declared type, and is visible
        switch ($targetType) {
            case TargetType::REVIEW:
                $target = $this->reviewRepository->findById($targetId);
                if (null === $target || ReviewStatus::PUBLISHED !== $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                if (!$this->placeLookup->isPublished($target->placeId())) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;

            case TargetType::PLACE_COMMENT:
                $target = $this->commentRepository->findById($targetId);
                if (null === $target || PlaceCommentStatus::PUBLISHED !== $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                if (!$this->placeLookup->isPublished($target->placeId())) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;

            case TargetType::FORUM_THREAD:
                $target = $this->threadRepository->findById($targetId);
                if (null === $target || ForumThreadStatus::PUBLISHED !== $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                $category = $this->categoryRepository->findById($target->categoryId());
                if (null === $category || !$category->isActive()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;

            case TargetType::FORUM_POST:
                $target = $this->postRepository->findById($targetId);
                if (null === $target || ForumPostStatus::PUBLISHED !== $target->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                $thread = $this->threadRepository->findById($target->threadId());
                if (null === $thread || ForumThreadStatus::PUBLISHED !== $thread->status()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                $category = $this->categoryRepository->findById($thread->categoryId());
                if (null === $category || !$category->isActive()) {
                    throw new ApiException(404, 'Target not found.', 'MISSING_PUBLIC_RESOURCE');
                }
                break;
        }

        // 2. Prevent duplicate open reports by the same reporter for the same target
        $existing = $this->reportRepository->findOpenByReporterAndTarget($reporterId, $targetId, $targetType);
        if (null !== $existing) {
            throw new ApiException(409, 'You have already reported this content.', 'REPORT_ALREADY_EXISTS');
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

        try {
            $this->reportRepository->save($report);
        } catch (UniqueConstraintViolationException $e) {
            throw new ApiException(409, 'You have already reported this content.', 'REPORT_ALREADY_EXISTS');
        }

        return $report;
    }
}
