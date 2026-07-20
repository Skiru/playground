<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Moderation\ContentReportRepository;
use App\Community\Domain\Moderation\ModerationActionRecord;
use App\Community\Domain\Moderation\ModerationActionRepository;
use App\Community\Domain\Moderation\ModerationActionType;
use App\Community\Domain\Moderation\TargetType;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\Review\ReviewRepository;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\TransactionManager;
use Symfony\Component\Uid\Uuid;

final class ModerateContent
{
    public function __construct(
        private readonly ContentReportRepository $reportRepository,
        private readonly ModerationActionRepository $moderationActionRepository,
        private readonly ReviewRepository $reviewRepository,
        private readonly PlaceCommentRepository $commentRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(
        Uuid $moderatorId,
        Uuid $targetId,
        TargetType $targetType,
        ModerationActionType $action,
        string $reason,
    ): void {
        $trimmedReason = trim($reason);
        if (empty($trimmedReason)) {
            throw new ApiException(400, 'Moderation reason cannot be empty.', 'VALIDATION_FAILURE');
        }

        $this->transactionManager->transactional(function () use ($moderatorId, $targetId, $targetType, $action, $trimmedReason): void {
            $now = $this->clock->now();
            $previousStatus = null;
            $resultingStatus = '';

            // 1. Load target and change state
            if (ModerationActionType::DISMISS_REPORT === $action) {
                $resultingStatus = 'DISMISSED';
            } else {
                switch ($targetType) {
                    case TargetType::REVIEW:
                        $target = $this->reviewRepository->findById($targetId);
                        if (null === $target) {
                            throw new ApiException(404, 'Review target not found.', 'MISSING_PUBLIC_RESOURCE');
                        }
                        $previousStatus = $target->status()->value;

                        if (ModerationActionType::HIDE === $action) {
                            $target->hide($now);
                        } elseif (ModerationActionType::REMOVE === $action) {
                            $target->removeByModerator($now);
                        } elseif (ModerationActionType::RESTORE === $action) {
                            $target->publish($now);
                        } else {
                            throw new ApiException(400, 'Action not supported for reviews.', 'INVALID_MODERATION_ACTION');
                        }

                        $this->reviewRepository->save($target);
                        $resultingStatus = $target->status()->value;
                        break;

                    case TargetType::PLACE_COMMENT:
                        $target = $this->commentRepository->findById($targetId);
                        if (null === $target) {
                            throw new ApiException(404, 'Comment target not found.', 'MISSING_PUBLIC_RESOURCE');
                        }
                        $previousStatus = $target->status()->value;

                        if (ModerationActionType::HIDE === $action) {
                            $target->hide($now);
                        } elseif (ModerationActionType::REMOVE === $action) {
                            $target->removeByModerator($now);
                        } elseif (ModerationActionType::RESTORE === $action) {
                            $target->publish($now);
                        } else {
                            throw new ApiException(400, 'Action not supported for place comments.', 'INVALID_MODERATION_ACTION');
                        }

                        $this->commentRepository->save($target);
                        $resultingStatus = $target->status()->value;
                        break;

                    case TargetType::FORUM_THREAD:
                        $target = $this->threadRepository->findById($targetId);
                        if (null === $target) {
                            throw new ApiException(404, 'Thread target not found.', 'MISSING_PUBLIC_RESOURCE');
                        }
                        $previousStatus = $target->status()->value;

                        if (ModerationActionType::HIDE === $action) {
                            $target->hide($now);
                            $resultingStatus = $target->status()->value;
                        } elseif (ModerationActionType::REMOVE === $action) {
                            $target->removeByModerator($now);
                            $resultingStatus = $target->status()->value;
                        } elseif (ModerationActionType::RESTORE === $action) {
                            $target->publish($now);
                            $resultingStatus = $target->status()->value;
                        } elseif (ModerationActionType::LOCK === $action) {
                            $target->lock($now);
                            $resultingStatus = 'LOCKED';
                        } elseif (ModerationActionType::UNLOCK === $action) {
                            $target->unlock($now);
                            $resultingStatus = 'UNLOCKED';
                        } elseif (ModerationActionType::PIN === $action) {
                            $target->pin($now);
                            $resultingStatus = 'PINNED';
                        } elseif (ModerationActionType::UNPIN === $action) {
                            $target->unpin($now);
                            $resultingStatus = 'UNPINNED';
                        } else {
                            throw new ApiException(400, 'Action not supported for forum threads.', 'INVALID_MODERATION_ACTION');
                        }

                        $this->threadRepository->save($target);
                        break;

                    case TargetType::FORUM_POST:
                        $target = $this->postRepository->findById($targetId);
                        if (null === $target) {
                            throw new ApiException(404, 'Post target not found.', 'MISSING_PUBLIC_RESOURCE');
                        }
                        $previousStatus = $target->status()->value;

                        if (ModerationActionType::HIDE === $action) {
                            $target->hide($now);
                        } elseif (ModerationActionType::REMOVE === $action) {
                            $target->removeByModerator($now);
                        } elseif (ModerationActionType::RESTORE === $action) {
                            $target->publish($now);
                        } else {
                            throw new ApiException(400, 'Action not supported for forum posts.', 'INVALID_MODERATION_ACTION');
                        }

                        $this->postRepository->save($target);
                        $resultingStatus = $target->status()->value;
                        break;
                }
            }

            // 2. Create immutable audit log record
            $record = new ModerationActionRecord(
                Uuid::v7(),
                $moderatorId,
                $targetType,
                $targetId,
                $action,
                $trimmedReason,
                $now,
                $previousStatus,
                $resultingStatus
            );
            $this->moderationActionRepository->save($record);

            // 3. Resolve/Dismiss any related open content reports for this target
            $openReports = $this->reportRepository->findOpenReportsForTarget($targetId, $targetType);
            foreach ($openReports as $report) {
                if (ModerationActionType::DISMISS_REPORT === $action) {
                    $report->dismiss($moderatorId, $now);
                } else {
                    $report->resolve($moderatorId, $now);
                }
                $this->reportRepository->save($report);
            }
        });
    }
}
