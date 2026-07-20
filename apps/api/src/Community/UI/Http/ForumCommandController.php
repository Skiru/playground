<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\CreateForumPost;
use App\Community\Application\UseCase\CreateForumThread;
use App\Community\Application\UseCase\DeleteOwnForumPost;
use App\Community\Application\UseCase\DeleteOwnForumThread;
use App\Community\Application\UseCase\EditOwnForumPost;
use App\Community\Application\UseCase\EditOwnForumThread;
use App\Shared\Application\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ForumCommandController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly CreateForumThread $createThreadUseCase,
        private readonly EditOwnForumThread $editThreadUseCase,
        private readonly DeleteOwnForumThread $deleteThreadUseCase,
        private readonly CreateForumPost $createPostUseCase,
        private readonly EditOwnForumPost $editPostUseCase,
        private readonly DeleteOwnForumPost $deletePostUseCase,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $forumThreadWrite,
        private readonly RateLimiterFactory $forumPostWrite,
    ) {
    }

    #[Route('/api/v1/forum/categories/{categoryId}/threads', name: 'api_forum_add_thread', methods: ['POST'])]
    public function createThread(string $categoryId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->forumThreadWrite, 'user_'.$user->getId()->toString());

        try {
            $categoryUuid = Uuid::fromString($categoryId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid category ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'title' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 5, max: 160),
            ],
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1, max: 10000),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $result = $this->createThreadUseCase->execute(
            $user->getId(),
            $categoryUuid,
            (string) $data['title'],
            (string) $data['body']
        );

        $thread = $result['thread'];
        $firstPost = $result['firstPost'];

        return new JsonResponse([
            'id' => $thread->id()->toString(),
            'categoryId' => $thread->categoryId()->toString(),
            'authorId' => $thread->authorId()->toString(),
            'title' => $thread->title(),
            'status' => $thread->status()->value,
            'createdAt' => $thread->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $thread->updatedAt()->format(\DateTimeInterface::ATOM),
            'lastActivityAt' => $thread->lastActivityAt()->format(\DateTimeInterface::ATOM),
            'version' => $thread->version(),
            'firstPost' => [
                'id' => $firstPost->id()->toString(),
                'body' => $firstPost->body(),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/me/forum-threads/{threadId}', name: 'api_me_update_thread', methods: ['PATCH'])]
    public function updateThread(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->forumThreadWrite, 'user_'.$user->getId()->toString());

        try {
            $threadUuid = Uuid::fromString($threadId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid thread ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'title' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 5, max: 160),
            ],
            'version' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('integer'),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $thread = $this->editThreadUseCase->execute(
            $user->getId(),
            $threadUuid,
            (int) $data['version'],
            (string) $data['title']
        );

        return new JsonResponse([
            'id' => $thread->id()->toString(),
            'categoryId' => $thread->categoryId()->toString(),
            'authorId' => $thread->authorId()->toString(),
            'title' => $thread->title(),
            'status' => $thread->status()->value,
            'createdAt' => $thread->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $thread->updatedAt()->format(\DateTimeInterface::ATOM),
            'lastActivityAt' => $thread->lastActivityAt()->format(\DateTimeInterface::ATOM),
            'version' => $thread->version(),
        ]);
    }

    #[Route('/api/v1/me/forum-threads/{threadId}', name: 'api_me_delete_thread', methods: ['DELETE'])]
    public function deleteThread(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        try {
            $threadUuid = Uuid::fromString($threadId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid thread ID format.', 'VALIDATION_FAILURE');
        }

        $this->deleteThreadUseCase->execute($user->getId(), $threadUuid);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/forum/threads/{threadId}/posts', name: 'api_forum_add_post', methods: ['POST'])]
    public function createPost(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->forumPostWrite, 'user_'.$user->getId()->toString());

        try {
            $threadUuid = Uuid::fromString($threadId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid thread ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1, max: 10000),
            ],
            'replyToPostId' => [
                new \Symfony\Component\Validator\Constraints\Type('string'),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $replyToUuid = null;
        if (isset($data['replyToPostId'])) {
            try {
                $replyToUuid = Uuid::fromString((string) $data['replyToPostId']);
            } catch (\InvalidArgumentException) {
                throw new ApiException(400, 'Invalid replyToPostId format.', 'VALIDATION_FAILURE');
            }
        }

        $post = $this->createPostUseCase->execute(
            $user->getId(),
            $threadUuid,
            $replyToUuid,
            (string) $data['body']
        );

        return new JsonResponse([
            'id' => $post->id()->toString(),
            'threadId' => $post->threadId()->toString(),
            'authorId' => $post->authorId()->toString(),
            'parentId' => $post->parentId()?->toString(),
            'body' => $post->body(),
            'status' => $post->status()->value,
            'createdAt' => $post->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $post->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $post->version(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/me/forum-posts/{postId}', name: 'api_me_update_post', methods: ['PATCH'])]
    public function updatePost(string $postId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit check
        $this->checkRateLimit($this->forumPostWrite, 'user_'.$user->getId()->toString());

        try {
            $postUuid = Uuid::fromString($postId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid post ID format.', 'VALIDATION_FAILURE');
        }

        $constraints = [
            'body' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1, max: 10000),
            ],
            'version' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('integer'),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        $post = $this->editPostUseCase->execute(
            $user->getId(),
            $postUuid,
            (int) $data['version'],
            (string) $data['body']
        );

        return new JsonResponse([
            'id' => $post->id()->toString(),
            'threadId' => $post->threadId()->toString(),
            'authorId' => $post->authorId()->toString(),
            'parentId' => $post->parentId()?->toString(),
            'body' => $post->body(),
            'status' => $post->status()->value,
            'createdAt' => $post->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $post->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $post->version(),
        ]);
    }

    #[Route('/api/v1/me/forum-posts/{postId}', name: 'api_me_delete_post', methods: ['DELETE'])]
    public function deletePost(string $postId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        try {
            $postUuid = Uuid::fromString($postId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid post ID format.', 'VALIDATION_FAILURE');
        }

        $this->deletePostUseCase->execute($user->getId(), $postUuid);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
