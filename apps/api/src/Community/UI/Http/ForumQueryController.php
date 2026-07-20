<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\UseCase\GetCommunityFeed;
use App\Community\Application\UseCase\GetForumThread;
use App\Community\Application\UseCase\ListCategoryThreads;
use App\Community\Application\UseCase\ListForumCategories;
use App\Community\Application\UseCase\ListForumPosts;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ForumQueryController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly ListForumCategories $listCategoriesUseCase,
        private readonly ListCategoryThreads $listThreadsUseCase,
        private readonly GetForumThread $getThreadUseCase,
        private readonly ListForumPosts $listPostsUseCase,
        private readonly GetCommunityFeed $getFeedUseCase,
    ) {
    }

    #[Route('/api/v1/forum/categories', name: 'api_forum_categories', methods: ['GET'])]
    public function listCategories(): JsonResponse
    {
        return new JsonResponse($this->listCategoriesUseCase->execute());
    }

    #[Route('/api/v1/forum/categories/{slug}/threads', name: 'api_forum_category_threads', methods: ['GET'])]
    public function listThreads(string $slug, Request $request): JsonResponse
    {
        $limit = $request->query->get('limit');
        $limitInt = null !== $limit && is_numeric($limit) ? min(50, max(1, (int) $limit)) : 20;
        $cursorStr = $request->query->get('cursor');

        $result = $this->listThreadsUseCase->execute($slug, $limitInt, $cursorStr);

        return new JsonResponse($result);
    }

    #[Route('/api/v1/forum/threads/{threadId}', name: 'api_forum_thread', methods: ['GET'])]
    public function getThread(string $threadId): JsonResponse
    {
        try {
            $threadUuid = Uuid::fromString($threadId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid thread ID format.', 'VALIDATION_FAILURE');
        }

        return new JsonResponse($this->getThreadUseCase->execute($threadUuid));
    }

    #[Route('/api/v1/forum/threads/{threadId}/posts', name: 'api_forum_thread_posts', methods: ['GET'])]
    public function listPosts(string $threadId, Request $request): JsonResponse
    {
        try {
            $threadUuid = Uuid::fromString($threadId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid thread ID format.', 'VALIDATION_FAILURE');
        }

        $limit = $request->query->get('limit');
        $limitInt = null !== $limit && is_numeric($limit) ? min(50, max(1, (int) $limit)) : 20;
        $cursorStr = $request->query->get('cursor');

        $result = $this->listPostsUseCase->execute($threadUuid, $limitInt, $cursorStr);

        return new JsonResponse($result);
    }

    #[Route('/api/v1/community/feed', name: 'api_community_feed', methods: ['GET'])]
    public function getFeed(Request $request): JsonResponse
    {
        $limit = $request->query->get('limit');
        $limitInt = null !== $limit && is_numeric($limit) ? min(50, max(1, (int) $limit)) : 20;
        $cursorStr = $request->query->get('cursor');

        $typeFilter = $request->query->get('type');
        if (null !== $typeFilter && '' === trim($typeFilter)) {
            $typeFilter = null;
        }

        $cityIdFilter = $request->query->get('cityId');
        if (null !== $cityIdFilter && '' === trim($cityIdFilter)) {
            $cityIdFilter = null;
        }

        $categoryIdFilter = $request->query->get('categoryId');
        if (null !== $categoryIdFilter && '' === trim($categoryIdFilter)) {
            $categoryIdFilter = null;
        }

        $result = $this->getFeedUseCase->execute($limitInt, $cursorStr, $typeFilter, $cityIdFilter, $categoryIdFilter);

        return new JsonResponse($result);
    }
}
