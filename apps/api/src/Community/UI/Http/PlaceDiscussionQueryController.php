<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\UseCase\ListComments;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PlaceDiscussionQueryController
{
    use ControllerHelperTrait;

    public function __construct(private readonly ListComments $listCommentsUseCase)
    {
    }

    #[Route('/api/v1/places/{placeId}/comments', name: 'api_places_comments', methods: ['GET'])]
    public function listComments(string $placeId, Request $request): JsonResponse
    {
        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid place ID format.', 'VALIDATION_FAILURE');
        }

        $limit = $request->query->get('limit');
        $limitInt = null !== $limit && is_numeric($limit) ? min(50, max(1, (int) $limit)) : 20;
        $cursorStr = $request->query->get('cursor');

        $result = $this->listCommentsUseCase->execute($placeUuid, $limitInt, $cursorStr);

        return new JsonResponse($result);
    }
}
