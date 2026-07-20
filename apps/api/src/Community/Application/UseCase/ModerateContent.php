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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private readonly Connection $connection,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(
        Uuid $moderatorId,
        Uuid $reportId,
        ModerationActionType $action,
        string $reason,
        ?string $correlationId = null,
    ): void {
        $trimmedReason = trim($reason);
        if (empty($trimmedReason)) {
            throw new ApiException(400, 'Moderation reason cannot be empty.', 'VALIDATION_FAILURE');
        }

        try {
            $this->transactionManager->transactional(function () use ($moderatorId, $reportId, $action, $trimmedReason, $correlationId): void {
                $now = $this->clock->now();

                // 1. Pessimistic lock on the report
                $reportRow = $this->connection->fetchAssociative(
                    'SELECT status, target_id, target_type FROM content_reports WHERE id = :id FOR UPDATE',
                    ['id' => $reportId->toRfc4122()]
                );

                if (false === $reportRow) {
                    throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
                }

                $reportStatus = (string) $reportRow['status'];
                if ('RESOLVED' === $reportStatus || 'DISMISSED' === $reportStatus) {
                    throw new ApiException(409, 'This report has already been resolved or dismissed.', 'MODERATION_CONFLICT');
                }

                $targetId = Uuid::fromString((string) $reportRow['target_id']);
                $targetType = TargetType::from((string) $reportRow['target_type']);

                $previousStatus = null;
                $resultingStatus = '';

                // 2. Pessimistic lock on the target and change state
                if (ModerationActionType::DISMISS_REPORT === $action) {
                    $resultingStatus = 'DISMISSED';
                } else {
                    switch ($targetType) {
                        case TargetType::REVIEW:
                            // Lock review row
                            $this->connection->fetchAssociative(
                                'SELECT id FROM reviews WHERE id = :id FOR UPDATE',
                                ['id' => $targetId->toRfc4122()]
                            );

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
                            // Lock comment row
                            $this->connection->fetchAssociative(
                                'SELECT id FROM place_comments WHERE id = :id FOR UPDATE',
                                ['id' => $targetId->toRfc4122()]
                            );

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
                            // Lock thread row
                            $this->connection->fetchAssociative(
                                'SELECT id FROM forum_threads WHERE id = :id FOR UPDATE',
                                ['id' => $targetId->toRfc4122()]
                            );

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
                            // Lock post row
                            $this->connection->fetchAssociative(
                                'SELECT id FROM forum_posts WHERE id = :id FOR UPDATE',
                                ['id' => $targetId->toRfc4122()]
                            );

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

                // 3. Create immutable audit log record
                $record = new ModerationActionRecord(
                    Uuid::v7(),
                    $moderatorId,
                    $targetType,
                    $targetId,
                    $action,
                    $trimmedReason,
                    $now,
                    $previousStatus,
                    $resultingStatus,
                    $reportId,
                    $correlationId
                );
                $this->moderationActionRepository->save($record);

                // 4. Resolve the selected report
                $report = $this->reportRepository->findById($reportId);
                if (null === $report) {
                    throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
                }

                if (ModerationActionType::DISMISS_REPORT === $action) {
                    $report->dismiss($moderatorId, $now);
                } else {
                    $report->resolve($moderatorId, $now);
                }
                $this->reportRepository->save($report);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new ApiException(409, 'This action is duplicate or already processed.', 'MODERATION_CONFLICT');
        }
    }
}
