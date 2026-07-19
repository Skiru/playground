<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Application\Port\PublishedPlaceLookup;
use App\Community\Application\Review\RatingSummaryLookup;
use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use App\Community\Domain\PlaceDiscussion\PlaceComment;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\TransactionManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CommunityController
{
    public function __construct(
        private readonly ReviewRepository $reviewRepository,
        private readonly RatingSummaryLookup $ratingSummaryLookup,
        private readonly PublishedPlaceLookup $publishedPlaceLookup,
        private readonly ActiveCommunityUserLookup $activeCommunityUserLookup,
        private readonly PublicAuthorProfileLookup $publicAuthorProfileLookup,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Clock $clock,
        private readonly TransactionManager $transactionManager,
        private readonly PlaceCommentRepository $placeCommentRepository,
    ) {
    }

    private function getAuthenticatedUser(): \App\Identity\Domain\User
    {
        $user = $this->security->getUser();
        if (!$user instanceof \App\Identity\Domain\User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        if (!$this->activeCommunityUserLookup->isActiveUser($user->getId())) {
            throw new AccessDeniedHttpException('User account is not active.');
        }

        return $user;
    }

    private function validateCsrf(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (null === $token || '' === trim($token)) {
            throw new AccessDeniedHttpException('CSRF token is missing.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('api_session', $token))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function setPrivateNoCache(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie');
    }

    #[Route('/api/v1/places/{placeId}/reviews', name: 'api_places_reviews', methods: ['GET'])]
    public function listReviews(string $placeId, Request $request): JsonResponse
    {
        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        $page = $request->query->get('page');
        $pageSize = $request->query->get('pageSize');
        $sort = $request->query->get('sort', 'newest');

        $pageInt = null !== $page && is_numeric($page) ? max(1, (int) $page) : 1;
        $pageSizeInt = null !== $pageSize && is_numeric($pageSize) ? min(50, max(1, (int) $pageSize)) : 10;

        $summary = $this->ratingSummaryLookup->getSummary($placeUuid);
        $reviews = $this->reviewRepository->findByPlaceId($placeUuid, $pageInt, $pageSizeInt, $sort);
        $totalItems = $this->reviewRepository->countByPlaceId($placeUuid);
        $totalPages = (int) ceil($totalItems / $pageSizeInt);

        $items = [];
        foreach ($reviews as $review) {
            $authorProfile = $this->publicAuthorProfileLookup->getProfile($review->authorId());
            $author = $authorProfile ?? [
                'id' => $review->authorId()->toString(),
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];

            $items[] = [
                'id' => $review->id()->toString(),
                'authorId' => $review->authorId()->toString(),
                'author' => $author,
                'rating' => $review->rating(),
                'body' => $review->body(),
                'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
                'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
                'version' => $review->version(),
            ];
        }

        return new JsonResponse([
            'summary' => $summary,
            'items' => $items,
            'pagination' => [
                'page' => $pageInt,
                'pageSize' => $pageSizeInt,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ]);
    }

    #[Route('/api/v1/places/{placeId}/reviews', name: 'api_places_add_review', methods: ['POST'])]
    public function addReview(string $placeId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        if (!$this->publishedPlaceLookup->isPublished($placeUuid)) {
            throw new NotFoundHttpException('Place not found or not published.');
        }

        // Check if user already reviewed this place
        $existing = $this->reviewRepository->findActiveByUserAndPlace($user->getId(), $placeUuid);
        if (null !== $existing) {
            throw new ConflictHttpException('You have already reviewed this place.');
        }

        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json') && 'json' !== $request->getContentTypeFormat()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new BadRequestHttpException('Payload too large.');
        }

        $data = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        // Reject extra fields
        $allowedFields = ['rating', 'body', 'visitedOn'];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new BadRequestHttpException(sprintf('Extra field "%s" is not allowed.', $key));
            }
        }

        $rating = $data['rating'] ?? null;
        $body = $data['body'] ?? null;
        $visitedOnStr = $data['visitedOn'] ?? null;

        if (null === $rating || !is_numeric($rating)) {
            throw new BadRequestHttpException('Missing or invalid rating.');
        }
        $ratingInt = (int) $rating;
        if ($ratingInt < 1 || $ratingInt > 5) {
            throw new UnprocessableEntityHttpException('Rating must be between 1 and 5.');
        }

        if (null === $body || !\is_string($body)) {
            throw new BadRequestHttpException('Missing or invalid body.');
        }
        $bodyStr = trim($body);
        if (mb_strlen($bodyStr) < 20 || mb_strlen($bodyStr) > 5000) {
            throw new UnprocessableEntityHttpException('Review body must be between 20 and 5000 characters.');
        }

        $visitedOn = null;
        if (null !== $visitedOnStr) {
            if (!\is_string($visitedOnStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitedOnStr)) {
                throw new BadRequestHttpException('Date must be in exact Y-m-d format.');
            }
            $visitedOn = \DateTimeImmutable::createFromFormat('Y-m-d', $visitedOnStr);
            if (!$visitedOn || $visitedOn->format('Y-m-d') !== $visitedOnStr) {
                throw new BadRequestHttpException('Date must be a valid calendar date.');
            }
            $visitedOn = $visitedOn->setTime(0, 0, 0);

            if ($visitedOn > $this->clock->now()->setTime(0, 0, 0)) {
                throw new UnprocessableEntityHttpException('Visited date cannot be in the future.');
            }
        }

        $now = $this->clock->now();
        $review = new Review(
            Uuid::v7(),
            $placeUuid,
            $user->getId(),
            $ratingInt,
            $bodyStr,
            $visitedOn,
            ReviewStatus::PUBLISHED,
            $now,
            $now
        );

        $this->reviewRepository->save($review);

        $response = new JsonResponse([
            'id' => $review->id()->toString(),
            'rating' => $review->rating(),
            'body' => $review->body(),
            'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
            'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $review->version(),
        ], Response::HTTP_CREATED);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/reviews/{reviewId}', name: 'api_me_update_review', methods: ['PATCH'])]
    public function updateReview(string $reviewId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $reviewUuid = Uuid::fromString($reviewId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid review ID format.');
        }

        $review = $this->reviewRepository->findById($reviewUuid);
        if (null === $review || $review->status() === ReviewStatus::DELETED_BY_AUTHOR || $review->status() === ReviewStatus::REMOVED_BY_MODERATOR) {
            throw new NotFoundHttpException('Review not found.');
        }

        if ($review->authorId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedHttpException('You cannot edit someone else\'s review.');
        }

        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json') && 'json' !== $request->getContentTypeFormat()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new BadRequestHttpException('Payload too large.');
        }

        $data = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        // Reject extra fields
        $allowedFields = ['rating', 'body', 'visitedOn', 'version'];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new BadRequestHttpException(sprintf('Extra field "%s" is not allowed.', $key));
            }
        }

        $expectedVersion = $data['version'] ?? null;
        if (null === $expectedVersion || !is_numeric($expectedVersion)) {
            throw new BadRequestHttpException('Missing or invalid expected version.');
        }

        if ($review->version() !== (int) $expectedVersion) {
            throw new ConflictHttpException('CONCURRENCY_ERROR');
        }

        $rating = $data['rating'] ?? $review->rating();
        $body = $data['body'] ?? $review->body();
        $visitedOnStr = \array_key_exists('visitedOn', $data) ? $data['visitedOn'] : ($review->visitedOn()?->format('Y-m-d'));

        $ratingInt = (int) $rating;
        if ($ratingInt < 1 || $ratingInt > 5) {
            throw new UnprocessableEntityHttpException('Rating must be between 1 and 5.');
        }

        $bodyStr = trim((string)$body);
        if (mb_strlen($bodyStr) < 20 || mb_strlen($bodyStr) > 5000) {
            throw new UnprocessableEntityHttpException('Review body must be between 20 and 5000 characters.');
        }

        $visitedOn = null;
        if (null !== $visitedOnStr) {
            if (!\is_string($visitedOnStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitedOnStr)) {
                throw new BadRequestHttpException('Date must be in exact Y-m-d format.');
            }
            $visitedOn = \DateTimeImmutable::createFromFormat('Y-m-d', $visitedOnStr);
            if (!$visitedOn || $visitedOn->format('Y-m-d') !== $visitedOnStr) {
                throw new BadRequestHttpException('Date must be a valid calendar date.');
            }
            $visitedOn = $visitedOn->setTime(0, 0, 0);

            if ($visitedOn > $this->clock->now()->setTime(0, 0, 0)) {
                throw new UnprocessableEntityHttpException('Visited date cannot be in the future.');
            }
        }

        $review->edit($ratingInt, $bodyStr, $visitedOn, $this->clock->now());

        try {
            $this->reviewRepository->save($review);
        } catch (\RuntimeException $e) {
            if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                throw new ConflictHttpException('CONCURRENCY_ERROR', $e);
            }
            throw $e;
        }

        $response = new JsonResponse([
            'id' => $review->id()->toString(),
            'rating' => $review->rating(),
            'body' => $review->body(),
            'visitedOn' => $review->visitedOn()?->format('Y-m-d'),
            'createdAt' => $review->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $review->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $review->version(),
        ]);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/reviews/{reviewId}', name: 'api_me_delete_review', methods: ['DELETE'])]
    public function deleteReview(string $reviewId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $reviewUuid = Uuid::fromString($reviewId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid review ID format.');
        }

        $review = $this->reviewRepository->findById($reviewUuid);
        if (null === $review || $review->status() === ReviewStatus::DELETED_BY_AUTHOR || $review->status() === ReviewStatus::REMOVED_BY_MODERATOR) {
            throw new NotFoundHttpException('Review not found.');
        }

        if ($review->authorId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedHttpException('You cannot delete someone else\'s review.');
        }

        $review->softDelete($this->clock->now());
        $this->reviewRepository->save($review);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/reviews', name: 'api_me_reviews', methods: ['GET'])]
    public function myReviews(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

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

        $response = new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $pageInt,
                'pageSize' => $pageSizeInt,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ]);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/places/{placeId}/comments', name: 'api_places_comments', methods: ['GET'])]
    public function listComments(string $placeId, Request $request): JsonResponse
    {
        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        $page = $request->query->get('page');
        $pageSize = $request->query->get('pageSize');

        $pageInt = null !== $page && is_numeric($page) ? max(1, (int) $page) : 1;
        $pageSizeInt = null !== $pageSize && is_numeric($pageSize) ? min(50, max(1, (int) $pageSize)) : 20;

        $comments = $this->placeCommentRepository->findByPlaceId($placeUuid, $pageInt, $pageSizeInt);
        $totalItems = $this->placeCommentRepository->countByPlaceId($placeUuid);
        $totalPages = (int) ceil($totalItems / $pageSizeInt);

        $items = [];
        foreach ($comments as $comment) {
            $authorProfile = $this->publicAuthorProfileLookup->getProfile($comment->authorId());
            $author = $authorProfile ?? [
                'id' => $comment->authorId()->toString(),
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];

            $items[] = [
                'id' => $comment->id()->toString(),
                'placeId' => $comment->placeId()->toString(),
                'authorId' => $comment->authorId()->toString(),
                'author' => $author,
                'parentId' => $comment->parentId()?->toString(),
                'body' => $comment->body(),
                'status' => $comment->status()->value,
                'createdAt' => $comment->createdAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $comment->updatedAt()->format(\DateTimeInterface::ATOM),
                'version' => $comment->version(),
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

    #[Route('/api/v1/places/{placeId}/comments', name: 'api_places_add_comment', methods: ['POST'])]
    public function addComment(string $placeId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        if (!$this->publishedPlaceLookup->isPublished($placeUuid)) {
            throw new NotFoundHttpException('Place not found or not published.');
        }

        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json') && 'json' !== $request->getContentTypeFormat()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new BadRequestHttpException('Payload too large.');
        }

        $data = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        // Reject extra fields
        $allowedFields = ['body'];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new BadRequestHttpException(sprintf('Extra field "%s" is not allowed.', $key));
            }
        }

        $body = $data['body'] ?? null;
        if (null === $body || !\is_string($body)) {
            throw new BadRequestHttpException('Missing or invalid body.');
        }
        $bodyStr = trim($body);
        if (mb_strlen($bodyStr) < 1 || mb_strlen($bodyStr) > 3000) {
            throw new UnprocessableEntityHttpException('Comment body must be between 1 and 3000 characters.');
        }

        $now = $this->clock->now();
        $comment = new PlaceComment(
            Uuid::v7(),
            $placeUuid,
            $user->getId(),
            null,
            $bodyStr,
            PlaceCommentStatus::PUBLISHED,
            $now,
            $now
        );

        $this->placeCommentRepository->save($comment);

        $response = new JsonResponse([
            'id' => $comment->id()->toString(),
            'placeId' => $comment->placeId()->toString(),
            'authorId' => $comment->authorId()->toString(),
            'parentId' => null,
            'body' => $comment->body(),
            'status' => $comment->status()->value,
            'createdAt' => $comment->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $comment->version(),
        ], Response::HTTP_CREATED);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/place-comments/{commentId}/replies', name: 'api_place_comments_add_reply', methods: ['POST'])]
    public function addReply(string $commentId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $parentUuid = Uuid::fromString($commentId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid parent comment ID format.');
        }

        $parent = $this->placeCommentRepository->findById($parentUuid);
        if (null === $parent || $parent->status() === PlaceCommentStatus::REMOVED_BY_MODERATOR) {
            throw new NotFoundHttpException('Parent comment not found.');
        }

        if (null !== $parent->parentId()) {
            throw new BadRequestHttpException('COMMENT_REPLY_DEPTH_LIMIT');
        }

        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json') && 'json' !== $request->getContentTypeFormat()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new BadRequestHttpException('Payload too large.');
        }

        $data = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        // Reject extra fields
        $allowedFields = ['body'];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new BadRequestHttpException(sprintf('Extra field "%s" is not allowed.', $key));
            }
        }

        $body = $data['body'] ?? null;
        if (null === $body || !\is_string($body)) {
            throw new BadRequestHttpException('Missing or invalid body.');
        }
        $bodyStr = trim($body);
        if (mb_strlen($bodyStr) < 1 || mb_strlen($bodyStr) > 3000) {
            throw new UnprocessableEntityHttpException('Comment body must be between 1 and 3000 characters.');
        }

        $now = $this->clock->now();
        $reply = new PlaceComment(
            Uuid::v7(),
            $parent->placeId(),
            $user->getId(),
            $parent->id(),
            $bodyStr,
            PlaceCommentStatus::PUBLISHED,
            $now,
            $now
        );

        $this->placeCommentRepository->save($reply);

        $response = new JsonResponse([
            'id' => $reply->id()->toString(),
            'placeId' => $reply->placeId()->toString(),
            'authorId' => $reply->authorId()->toString(),
            'parentId' => $reply->parentId()->toString(),
            'body' => $reply->body(),
            'status' => $reply->status()->value,
            'createdAt' => $reply->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $reply->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $reply->version(),
        ], Response::HTTP_CREATED);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/place-comments/{commentId}', name: 'api_me_update_comment', methods: ['PATCH'])]
    public function updateComment(string $commentId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $commentUuid = Uuid::fromString($commentId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid comment ID format.');
        }

        $comment = $this->placeCommentRepository->findById($commentUuid);
        if (null === $comment || $comment->status() === PlaceCommentStatus::DELETED_BY_AUTHOR || $comment->status() === PlaceCommentStatus::REMOVED_BY_MODERATOR) {
            throw new NotFoundHttpException('Comment not found.');
        }

        if ($comment->authorId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedHttpException('You cannot edit someone else\'s comment.');
        }

        $contentType = $request->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json') && 'json' !== $request->getContentTypeFormat()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new BadRequestHttpException('Payload too large.');
        }

        $data = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        // Reject extra fields
        $allowedFields = ['body', 'version'];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new BadRequestHttpException(sprintf('Extra field "%s" is not allowed.', $key));
            }
        }

        $expectedVersion = $data['version'] ?? null;
        if (null === $expectedVersion || !is_numeric($expectedVersion)) {
            throw new BadRequestHttpException('Missing or invalid expected version.');
        }

        if ($comment->version() !== (int) $expectedVersion) {
            throw new ConflictHttpException('CONCURRENCY_ERROR');
        }

        $body = $data['body'] ?? $comment->body();
        if (!\is_string($body)) {
            throw new BadRequestHttpException('Body must be a string.');
        }
        $bodyStr = trim($body);
        if (mb_strlen($bodyStr) < 1 || mb_strlen($bodyStr) > 3000) {
            throw new UnprocessableEntityHttpException('Comment body must be between 1 and 3000 characters.');
        }

        $comment->edit($bodyStr, $this->clock->now());

        try {
            $this->placeCommentRepository->save($comment);
        } catch (\RuntimeException $e) {
            if ('CONCURRENCY_ERROR' === $e->getMessage()) {
                throw new ConflictHttpException('CONCURRENCY_ERROR', $e);
            }
            throw $e;
        }

        $response = new JsonResponse([
            'id' => $comment->id()->toString(),
            'placeId' => $comment->placeId()->toString(),
            'authorId' => $comment->authorId()->toString(),
            'parentId' => $comment->parentId()?->toString(),
            'body' => $comment->body(),
            'status' => $comment->status()->value,
            'createdAt' => $comment->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $comment->version(),
        ]);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/place-comments/{commentId}', name: 'api_me_delete_comment', methods: ['DELETE'])]
    public function deleteComment(string $commentId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser();

        try {
            $commentUuid = Uuid::fromString($commentId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid comment ID format.');
        }

        $comment = $this->placeCommentRepository->findById($commentUuid);
        if (null === $comment || $comment->status() === PlaceCommentStatus::DELETED_BY_AUTHOR || $comment->status() === PlaceCommentStatus::REMOVED_BY_MODERATOR) {
            throw new NotFoundHttpException('Comment not found.');
        }

        if ($comment->authorId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedHttpException('You cannot delete someone else\'s comment.');
        }

        $comment->softDelete($this->clock->now());
        $this->placeCommentRepository->save($comment);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $this->setPrivateNoCache($response);

        return $response;
    }
}
