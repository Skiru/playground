<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\CreateComment;
use App\Community\Application\UseCase\CreateReply;
use App\Community\Application\UseCase\DeleteComment;
use App\Community\Application\UseCase\UpdateComment;
use App\Shared\Application\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PlaceDiscussionCommandController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly CreateComment $createCommentUseCase,
        private readonly CreateReply $createReplyUseCase,
        private readonly UpdateComment $updateCommentUseCase,
        private readonly DeleteComment $deleteCommentUseCase,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $commentWrite,
    ) {
    }

    #[Route('/api/v1/places/{placeId}/comments', name: 'api_places_add_comment', methods: ['POST'])]
    public function addComment(string $placeId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->commentWrite, 'user_'.$user->getId()->toString());

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid place ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1, max: 3000),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $comment = $this->createCommentUseCase->execute($user->getId(), $placeUuid, (string) $data['body']);

        return new JsonResponse([
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
    }

    #[Route('/api/v1/place-comments/{commentId}/replies', name: 'api_place_comments_add_reply', methods: ['POST'])]
    public function addReply(string $commentId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->commentWrite, 'user_'.$user->getId()->toString());

        try {
            $parentUuid = Uuid::fromString($commentId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid parent comment ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1, max: 3000),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $reply = $this->createReplyUseCase->execute($user->getId(), $parentUuid, (string) $data['body']);

        return new JsonResponse([
            'id' => $reply->id()->toString(),
            'placeId' => $reply->placeId()->toString(),
            'authorId' => $reply->authorId()->toString(),
            'parentId' => $reply->parentId()?->toString(),
            'body' => $reply->body(),
            'status' => $reply->status()->value,
            'createdAt' => $reply->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $reply->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $reply->version(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/me/place-comments/{commentId}', name: 'api_me_update_comment', methods: ['PATCH'])]
    public function updateComment(string $commentId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->commentWrite, 'user_'.$user->getId()->toString());

        try {
            $commentUuid = Uuid::fromString($commentId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid comment ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1, max: 3000),
            ],
            'version' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('integer'),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $comment = $this->updateCommentUseCase->execute(
            $user->getId(),
            $commentUuid,
            (int) $data['version'],
            (string) $data['body']
        );

        return new JsonResponse([
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
    }

    #[Route('/api/v1/me/place-comments/{commentId}', name: 'api_me_delete_comment', methods: ['DELETE'])]
    public function deleteComment(string $commentId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        try {
            $commentUuid = Uuid::fromString($commentId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid comment ID format.', 'VALIDATION_FAILURE');
        }

        $this->deleteCommentUseCase->execute($user->getId(), $commentUuid);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
