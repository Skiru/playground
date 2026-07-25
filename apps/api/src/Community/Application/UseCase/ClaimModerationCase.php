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
            // Pessimistic lock on the report
            $row = $this->connection->fetchAssociative(
                'SELECT status, claimed_by FROM content_reports WHERE id = :id FOR UPDATE',
                ['id' => $reportId->toRfc4122()]
            );

            if (false === $row) {
                throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
            }

            $currentStatus = (string) $row['status'];
            $claimedBy = null === $row['claimed_by'] ? null : (string) $row['claimed_by'];
            if ('IN_REVIEW' === $currentStatus && $moderatorId->toRfc4122() === $claimedBy) {
                return;
            }
            if ('OPEN' !== $currentStatus) {
                throw new ApiException(409, 'Only OPEN reports can be claimed.', 'MODERATION_CONFLICT');
            }

            $report = $this->reportRepository->findById($reportId);
            if (null === $report) {
                throw new ApiException(404, 'Moderation case not found.', 'MISSING_PUBLIC_RESOURCE');
            }

            $report->claim($moderatorId, $this->clock->now());
            $this->reportRepository->save($report);
        });
    }
}
