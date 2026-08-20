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
        string $idempotencyKey,
        ?string $correlationId = null,
    ): void {
        $trimmedReason = trim($reason);
        if ('' === $trimmedReason) {
            throw new ApiException(400, 'Moderation reason cannot be empty.', 'VALIDATION_FAILURE');
        }

        $requestFingerprint = hash('sha256', json_encode([
            'reportId' => $reportId->toRfc4122(),
            'action' => $action->value,
            'reason' => $trimmedReason,
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->transactionManager->transactional(function () use ($moderatorId, $reportId, $action, $trimmedReason, $idempotencyKey, $requestFingerprint, $correlationId): void {
                $now = $this->clock->now();

                $insertedKey = $this->connection->fetchOne(
                    'INSERT INTO moderation_idempotency_keys (idempotency_key, moderator_id, endpoint, report_id, request_fingerprint, outcome_status, outcome_code, created_at)
                     VALUES (:idempotency_key, :moderator_id, :endpoint, :report_id, :request_fingerprint, :outcome_status, :outcome_code, :created_at)
                     ON CONFLICT (idempotency_key) DO NOTHING
                     RETURNING idempotency_key',
                    [
                        'idempotency_key' => $idempotencyKey,
                        'moderator_id' => $moderatorId->toRfc4122(),
                        'endpoint' => 'POST:/api/v1/moderation/action',
                        'report_id' => $reportId->toRfc4122(),
                        'request_fingerprint' => $requestFingerprint,
                        'outcome_status' => 0,
                        'outcome_code' => 'PENDING',
                        'created_at' => $now->format('Y-m-d H:i:s'),
                    ],
                );

                if (false === $insertedKey) {
                    $existingKey = $this->connection->fetchAssociative(
                        'SELECT moderator_id, endpoint, report_id, request_fingerprint, outcome_status FROM moderation_idempotency_keys WHERE idempotency_key = :idempotency_key',
                        ['idempotency_key' => $idempotencyKey],
                    );

                    if (false === $existingKey) {
                        throw new ApiException(409, 'Idempotency key is already in use.', 'IDEMPOTENCY_KEY_REUSE');
                    }

                    $isReplay = $existingKey['moderator_id'] === $moderatorId->toRfc4122()
                        && 'POST:/api/v1/moderation/action' === $existingKey['endpoint']
                        && $existingKey['report_id'] === $reportId->toRfc4122()
                        && $existingKey['request_fingerprint'] === $requestFingerprint;

                    if ($isReplay && 200 === (int) $existingKey['outcome_status']) {
                        return;
                    }

                    if ($isReplay) {
                        throw new ApiException(409, 'Idempotency key is already being processed.', 'IDEMPOTENCY_KEY_REUSE');
                    }

                    throw new ApiException(409, 'Idempotency key cannot be reused with a different moderation request.', 'IDEMPOTENCY_KEY_REUSE');
                }

                if (null !== $correlationId) {
                    $existingCorrelation = $this->connection->fetchOne(
                        'SELECT id FROM moderation_actions WHERE correlation_id = :correlation_id LIMIT 1',
                        ['correlation_id' => $correlationId],
                    );
                    if (false !== $existingCorrelation) {
                        $correlationId = null;
                    }
                }

                $reportRow = $this->connection->fetchAssociative(
                    'SELECT status, target_id, target_type, claimed_by, claimed_at FROM content_reports WHERE id = :id FOR UPDATE',
                    ['id' => $reportId->toRfc4122()]
                );

                if (false === $reportRow) {
                    throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
                }

                $reportStatus = (string) $reportRow['status'];
                if ('RESOLVED' === $reportStatus || 'DISMISSED' === $reportStatus) {
                    throw new ApiException(409, 'This report has already been resolved or dismissed.', 'MODERATION_CONFLICT');
                }
                $claimedBy = null === $reportRow['claimed_by'] ? null : (string) $reportRow['claimed_by'];
                if ('IN_REVIEW' !== $reportStatus || $moderatorId->toRfc4122() !== $claimedBy) {
                    throw new ApiException(409, 'Only the moderator who claimed this case can process it.', 'MODERATION_OWNERSHIP_CONFLICT');
                }

                $claimedAtRaw = null === $reportRow['claimed_at'] ? null : (string) $reportRow['claimed_at'];
                $claimedAt = null !== $claimedAtRaw ? new \DateTimeImmutable($claimedAtRaw) : null;
                $isExpired = null !== $claimedAt && ($now->getTimestamp() - $claimedAt->getTimestamp() >= \App\Community\Domain\Moderation\ContentReport::CLAIM_LEASE_SECONDS);
                if ($isExpired) {
                    throw new ApiException(409, 'Your claim lease on this case has expired.', 'MODERATION_CLAIM_EXPIRED');
                }

                $targetId = Uuid::fromString((string) $reportRow['target_id']);
                $targetType = TargetType::from((string) $reportRow['target_type']);

                $previousStatus = null;
                $resultingStatus = '';
                $actionMetadata = [];

                if (ModerationActionType::DISMISS_REPORT === $action || ModerationActionType::RESOLVE_REPORT === $action) {
                    $previousStatus = $this->lockAndReadTargetStatus($targetType, $targetId);
                    $resultingStatus = $previousStatus;
                } else {
                    switch ($targetType) {
                        case TargetType::REVIEW:
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
                            } elseif (ModerationActionType::REMOVE === $action) {
                                $target->removeByModerator($now);
                            } elseif (ModerationActionType::RESTORE === $action) {
                                $target->publish($now);
                            } elseif (ModerationActionType::LOCK === $action) {
                                $target->lock($now);
                            } elseif (ModerationActionType::UNLOCK === $action) {
                                $target->unlock($now);
                            } elseif (ModerationActionType::PIN === $action) {
                                $target->pin($now);
                            } elseif (ModerationActionType::UNPIN === $action) {
                                $target->unpin($now);
                            } else {
                                throw new ApiException(400, 'Action not supported for forum threads.', 'INVALID_MODERATION_ACTION');
                            }

                            $this->threadRepository->save($target);
                            $resultingStatus = $target->status()->value;
                            $actionMetadata = [
                                'isLocked' => null !== $target->lockedAt(),
                                'isPinned' => null !== $target->pinnedAt(),
                            ];
                            break;

                        case TargetType::FORUM_POST:
                            $this->connection->fetchAssociative(
                                'SELECT id FROM forum_posts WHERE id = :id FOR UPDATE',
                                ['id' => $targetId->toRfc4122()]
                            );

                            $target = $this->postRepository->findById($targetId);
                            if (null === $target) {
                                throw new ApiException(404, 'Post target not found.', 'MISSING_PUBLIC_RESOURCE');
                            }
                            if ($target->isInitial()) {
                                throw new ApiException(409, 'Initial forum posts must be moderated through the thread target.', 'INITIAL_POST_REQUIRES_THREAD_TARGET');
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
                    $correlationId,
                    $actionMetadata
                );
                $this->moderationActionRepository->save($record);

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

                $this->connection->update('moderation_idempotency_keys', [
                    'outcome_status' => 200,
                    'outcome_code' => 'success',
                ], [
                    'idempotency_key' => $idempotencyKey,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new ApiException(409, 'This action is duplicate or already processed.', 'MODERATION_CONFLICT');
        } catch (\LogicException $e) {
            throw new ApiException(409, $e->getMessage(), 'MODERATION_STATE_CONFLICT', previous: $e);
        }
    }

    private function lockAndReadTargetStatus(TargetType $targetType, Uuid $targetId): string
    {
        $table = match ($targetType) {
            TargetType::REVIEW => 'reviews',
            TargetType::PLACE_COMMENT => 'place_comments',
            TargetType::FORUM_THREAD => 'forum_threads',
            TargetType::FORUM_POST => 'forum_posts',
        };
        $status = $this->connection->fetchOne(
            "SELECT status FROM {$table} WHERE id = :id FOR UPDATE",
            ['id' => $targetId->toRfc4122()],
        );
        if (false === $status) {
            throw new ApiException(404, 'Moderation target not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        return (string) $status;
    }
}
