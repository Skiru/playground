<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Application\Port\PublishedPlaceLookup;
use App\Community\Application\Review\RatingSummaryLookup;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class ListReviews
{
    public function __construct(
        private readonly ReviewRepository $reviewRepository,
        private readonly RatingSummaryLookup $ratingSummaryLookup,
        private readonly PublishedPlaceLookup $publishedPlaceLookup,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Uuid $placeId, int $page, int $pageSize, string $sort): array
    {
        if (!$this->publishedPlaceLookup->isPublished($placeId)) {
            throw new ApiException(404, 'Place not found.', 'MISSING_PUBLIC_RESOURCE');
        }

        $summary = $this->ratingSummaryLookup->getSummary($placeId);
        $reviews = $this->reviewRepository->findByPlaceId($placeId, $page, $pageSize, $sort);
        $totalItems = $this->reviewRepository->countByPlaceId($placeId);
        $totalPages = (int) ceil($totalItems / $pageSize);

        $authorIds = array_map(static fn ($r) => $r->authorId(), $reviews);
        $profiles = $this->authorProfileLookup->getProfiles($authorIds);

        $items = [];
        foreach ($reviews as $review) {
            $authorIdStr = $review->authorId()->toString();

            // Tombstone check
            if (ReviewStatus::DELETED_BY_AUTHOR === $review->status()) {
                $author = [
                    'id' => $authorIdStr,
                    'displayName' => 'Usunięty użytkownik',
                    'initials' => 'U',
                ];
                $body = 'Treść usunięta przez autora';
            } else {
                $author = $profiles[$authorIdStr] ?? [
                    'id' => $authorIdStr,
                    'displayName' => 'Usunięty użytkownik',
                    'initials' => 'U',
                ];
                $body = $review->body();
            }

            $items[] = [
                'id' => $review->id()->toString(),
                'authorId' => $authorIdStr,
                'author' => $author,
                'rating' => $review->rating(),
                'body' => $body,
                'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
                'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
                'version' => $review->version(),
            ];
        }

        return [
            'summary' => $summary,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ];
    }
}
