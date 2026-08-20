<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Moderation\ContentReportRepository;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class ClaimModerationCase
{
    public function __construct(
        private readonly ContentReportRepository $reportRepository,
        private readonly Connection $connection,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $reportId, Uuid $moderatorId): void
    {
        $this->transactionManager->transactional(function () use ($reportId, $moderatorId): void {
            $now = $this->clock->now();

            // Pessimistic lock on the report
            $row = $this->connection->fetchAssociative(
                'SELECT status, claimed_by, claimed_at FROM content_reports WHERE id = :id FOR UPDATE',
                ['id' => $reportId->toRfc4122()]
            );

            if (false === $row) {
                throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
            }

            $currentStatus = (string) $row['status'];
            $claimedBy = null === $row['claimed_by'] ? null : (string) $row['claimed_by'];
            $claimedAtRaw = null === $row['claimed_at'] ? null : (string) $row['claimed_at'];
            $claimedAt = null !== $claimedAtRaw ? new \DateTimeImmutable($claimedAtRaw) : null;

            $isExpired = null !== $claimedAt && ($now->getTimestamp() - $claimedAt->getTimestamp() >= \App\Community\Domain\Moderation\ContentReport::CLAIM_LEASE_SECONDS);

            if ('IN_REVIEW' === $currentStatus) {
                if ($moderatorId->toRfc4122() === $claimedBy && !$isExpired) {
                    $this->connection->executeStatement(
                        'UPDATE content_reports SET claimed_at = :claimed_at WHERE id = :id',
                        [
                            'claimed_at' => $now->format('Y-m-d H:i:s'),
                            'id' => $reportId->toRfc4122(),
                        ]
                    );

                    return;
                }

                if (!$isExpired && $moderatorId->toRfc4122() !== $claimedBy) {
                    throw new ApiException(409, 'This moderation case is actively claimed by another moderator.', 'MODERATION_CLAIM_ACTIVE');
                }
            } elseif ('OPEN' !== $currentStatus) {
                throw new ApiException(409, 'Only OPEN or expired IN_REVIEW reports can be claimed.', 'MODERATION_CONFLICT');
            }

            $report = $this->reportRepository->findById($reportId);
            if (null === $report) {
                throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
            }

            $report->claim($moderatorId, $now);
            $this->reportRepository->save($report);
        });
    }
}
