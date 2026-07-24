<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\ListReviews;
use App\Community\Domain\Review\ReviewRepository;
use App\Shared\Application\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ReviewQueryController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly ListReviews $listReviewsUseCase,
        private readonly ReviewRepository $reviewRepository,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
    ) {
    }

    #[Route('/api/v1/places/{placeId}/reviews', name: 'api_places_reviews', methods: ['GET'])]
    public function listReviews(string $placeId, Request $request): JsonResponse
    {
        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid place ID format.', 'VALIDATION_FAILURE');
        }

        $page = $request->query->get('page');
        $pageSize = $request->query->get('pageSize');
        $sort = $request->query->get('sort', 'newest');

        $pageInt = null !== $page && is_numeric($page) ? max(1, (int) $page) : 1;
        $pageSizeInt = null !== $pageSize && is_numeric($pageSize) ? min(50, max(1, (int) $pageSize)) : 10;

        $result = $this->listReviewsUseCase->execute($placeUuid, $pageInt, $pageSizeInt, $sort);

        $response = new JsonResponse($result);
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    #[Route('/api/v1/me/reviews', name: 'api_me_reviews', methods: ['GET'])]
    public function myReviews(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        $page = $request->query->get('page');
        $pageSize = $request->query->get('pageSize');

        $pageInt = null !== $page && is_numeric($page) ? max(1, (int) $page) : 1;
        $pageSizeInt = null !== $pageSize && is_numeric($pageSize) ? min(50, max(1, (int) $pageSize)) : 10;

        $reviews = $this->reviewRepository->findByAuthorId($user->getId(), $pageInt, $pageSizeInt);
        $totalItems = $this->reviewRepository->countByAuthorId($user->getId());
        $totalPages = (int) ceil($totalItems / $pageSizeInt);

        $items = [];
        foreach ($reviews as $review) {
            $items[] = [
                'id' => $review->id()->toString(),
                'placeId' => $review->placeId()->toString(),
                'rating' => $review->rating(),
                'body' => $review->body(),
                'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
                'status' => $review->status()->value,
                'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
                'version' => $review->version(),
            ];
        }

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $pageInt,
                'pageSize' => $pageSizeInt,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ]);
    }
}
