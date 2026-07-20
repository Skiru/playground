<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\CreateReview;
use App\Community\Application\UseCase\DeleteReview;
use App\Community\Application\UseCase\UpdateReview;
use App\Shared\Application\Clock;
use App\Shared\Application\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ReviewCommandController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly CreateReview $createReviewUseCase,
        private readonly UpdateReview $updateReviewUseCase,
        private readonly DeleteReview $deleteReviewUseCase,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
        private readonly Clock $clock,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $reviewWrite,
    ) {
    }

    #[Route('/api/v1/places/{placeId}/reviews', name: 'api_places_add_review', methods: ['POST'])]
    public function addReview(string $placeId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->reviewWrite, 'user_'.$user->getId()->toString());

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid place ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'rating' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('integer'),
                new \Symfony\Component\Validator\Constraints\Range(min: 1, max: 5),
            ],
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 20, max: 5000),
            ],
            'visitedOn' => [
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Regex(
                    pattern: '/^\d{4}-\d{2}-\d{2}$/',
                    message: 'Date must be in exact Y-m-d format.'
                ),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $visitedOn = null;
        if (isset($data['visitedOn'])) {
            $visitedOn = \DateTimeImmutable::createFromFormat('Y-m-d', $data['visitedOn']);
            if (!$visitedOn || $visitedOn->format('Y-m-d') !== $data['visitedOn']) {
                throw new ApiException(400, 'Date must be a valid calendar date.', 'VALIDATION_FAILURE');
            }
            $visitedOn = $visitedOn->setTime(0, 0, 0);
            if ($visitedOn > $this->clock->now()->setTime(0, 0, 0)) {
                throw new ApiException(400, 'Visited date cannot be in the future.', 'VALIDATION_FAILURE');
            }
        }

        $review = $this->createReviewUseCase->execute(
            $user->getId(),
            $placeUuid,
            (int) $data['rating'],
            (string) $data['body'],
            $visitedOn
        );

        return new JsonResponse([
            'id' => $review->id()->toString(),
            'rating' => $review->rating(),
            'body' => $review->body(),
            'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
            'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $review->version(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/me/reviews/{reviewId}', name: 'api_me_update_review', methods: ['PATCH'])]
    public function updateReview(string $reviewId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->reviewWrite, 'user_'.$user->getId()->toString());

        try {
            $reviewUuid = Uuid::fromString($reviewId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid review ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'rating' => [
                new \Symfony\Component\Validator\Constraints\Type('integer'),
                new \Symfony\Component\Validator\Constraints\Range(min: 1, max: 5),
            ],
            'body' => [
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 20, max: 5000),
            ],
            'visitedOn' => [
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Regex(
                    pattern: '/^\d{4}-\d{2}-\d{2}$/',
                    message: 'Date must be in exact Y-m-d format.'
                ),
            ],
            'version' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('integer'),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        // Load existing to merge updated fields
        $visitedOn = null;
        if (\array_key_exists('visitedOn', $data)) {
            if (null !== $data['visitedOn']) {
                $visitedOn = \DateTimeImmutable::createFromFormat('Y-m-d', $data['visitedOn']);
                if (!$visitedOn || $visitedOn->format('Y-m-d') !== $data['visitedOn']) {
                    throw new ApiException(400, 'Date must be a valid calendar date.', 'VALIDATION_FAILURE');
                }
                $visitedOn = $visitedOn->setTime(0, 0, 0);
                if ($visitedOn > $this->clock->now()->setTime(0, 0, 0)) {
                    throw new ApiException(400, 'Visited date cannot be in the future.', 'VALIDATION_FAILURE');
                }
            }
        }

        $review = $this->updateReviewUseCase->execute(
            $user->getId(),
            $reviewUuid,
            (int) $data['version'],
            (int) ($data['rating'] ?? 5), // will fall back inside updateReview if not set
            (string) ($data['body'] ?? ''),
            $visitedOn
        );

        return new JsonResponse([
            'id' => $review->id()->toString(),
            'rating' => $review->rating(),
            'body' => $review->body(),
            'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
            'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $review->version(),
        ]);
    }

    #[Route('/api/v1/me/reviews/{reviewId}', name: 'api_me_delete_review', methods: ['DELETE'])]
    public function deleteReview(string $reviewId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        try {
            $reviewUuid = Uuid::fromString($reviewId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid review ID format.', 'VALIDATION_FAILURE');
        }

        $this->deleteReviewUseCase->execute($user->getId(), $reviewUuid);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
