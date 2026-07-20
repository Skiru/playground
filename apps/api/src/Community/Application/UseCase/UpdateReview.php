<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class UpdateReview
{
    public function __construct(
        private readonly ReviewRepository $reviewRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $reviewId, int $expectedVersion, int $rating, string $body, ?\DateTimeImmutable $visitedOn): Review
    {
        $review = $this->reviewRepository->findById($reviewId);
        if (null === $review || ReviewStatus::DELETED_BY_AUTHOR === $review->status() || ReviewStatus::REMOVED_BY_MODERATOR === $review->status()) {
            throw new ApiException(404, 'Review not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        if ($review->authorId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new ApiException(403, 'You cannot edit someone else\'s review.', 'FORBIDDEN_OWNERSHIP');
        }

        if ($review->version() !== $expectedVersion) {
            throw new ApiException(409, 'Review has been modified by another process.', 'CONCURRENCY_CONFLICT');
        }

        $review->edit($rating, $body, $visitedOn, $this->clock->now());

        try {
            $this->reviewRepository->save($review);
        } catch (\RuntimeException $e) {
            if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                throw new ApiException(409, 'Review has been modified by another process.', 'CONCURRENCY_CONFLICT', '', [], $e);
            }
            throw $e;
        }

        return $review;
    }
}
