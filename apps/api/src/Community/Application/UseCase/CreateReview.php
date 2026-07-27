<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublishedPlaceLookup;
use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class CreateReview
{
    public function __construct(
        private readonly ReviewRepository $reviewRepository,
        private readonly PublishedPlaceLookup $publishedPlaceLookup,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Uuid $userId, Uuid $placeId, int $rating, string $body, ?\DateTimeImmutable $visitedOn): Review
    {
        if (!$this->publishedPlaceLookup->isPublished($placeId)) {
            throw new ApiException(404, 'Place not found or not published.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Pre-check to reduce chances of race
        $existing = $this->reviewRepository->findActiveByUserAndPlace($userId, $placeId);
        if (null !== $existing) {
            throw new ApiException(409, 'You have already reviewed this place.', 'REVIEW_ALREADY_EXISTS');
        }

        $now = $this->clock->now();
        $review = new Review(
            Uuid::v7(),
            $placeId,
            $userId,
            $rating,
            $body,
            $visitedOn,
            ReviewStatus::PUBLISHED,
            $now,
            $now
        );

        try {
            $this->reviewRepository->save($review);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            throw new ApiException(409, 'You have already reviewed this place.', 'REVIEW_ALREADY_EXISTS', '', [], $e);
        } catch (\Exception $e) {
            // Check if error message contains unique constraint signature
            if (str_contains(strtolower($e->getMessage()), 'unique constraint') || str_contains(strtolower($e->getMessage()), 'duplicate key')) {
                throw new ApiException(409, 'You have already reviewed this place.', 'REVIEW_ALREADY_EXISTS', '', [], $e);
            }
            throw $e;
        }

        return $review;
    }
}
