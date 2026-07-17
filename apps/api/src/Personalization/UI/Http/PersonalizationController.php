<?php

declare(strict_types=1);

namespace App\Personalization\UI\Http;

use App\Identity\Infrastructure\Security\CsrfValidator;
use App\Personalization\Application\FavoriteRepository;
use App\Personalization\Application\VisitRepository;
use App\Personalization\Domain\Favorite;
use App\Personalization\Domain\PublishedPlaceLookup;
use App\Personalization\Domain\Visit;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PersonalizationController
{
    public function __construct(
        private readonly FavoriteRepository $favoriteRepository,
        private readonly VisitRepository $visitRepository,
        private readonly PublishedPlaceLookup $placeLookup,
        private readonly Security $security,
        private readonly CsrfValidator $csrfValidator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getAuthenticatedUser()
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new AccessDeniedHttpException('Authentication required.');
        }
        return $user;
    }

    #[Route('/api/v1/me/favorites', name: 'api_get_favorites', methods: ['GET'])]
    public function listFavorites(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = min(50, max(1, $request->query->getInt('pageSize', 20)));

        $favorites = $this->favoriteRepository->findByUserId($user->getId(), $page, $pageSize);
        $totalItems = $this->favoriteRepository->countByUserId($user->getId());
        $totalPages = (int) ceil($totalItems / $pageSize);

        $items = [];
        foreach ($favorites as $fav) {
            $items[] = [
                'id' => $fav->getId()->toString(),
                'placeId' => $fav->getPlaceId()->toString(),
                'createdAt' => $fav->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ]);
    }

    #[Route('/api/v1/places/{placeId}/favorite', name: 'api_add_favorite', methods: ['PUT'])]
    public function addFavorite(string $placeId, Request $request): JsonResponse
    {
        $this->csrfValidator->validate($request);
        $user = $this->getAuthenticatedUser();

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        if (!$this->placeLookup->existsAndPublished($placeUuid)) {
            return new JsonResponse([
                'title' => 'Not Found',
                'status' => Response::HTTP_NOT_FOUND,
                'detail' => 'Place not found or not published.',
            ], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->favoriteRepository->findByUserAndPlace($user->getId(), $placeUuid);
        if (null === $existing) {
            $favorite = new Favorite($user, $placeUuid, new \DateTimeImmutable());
            $this->favoriteRepository->save($favorite);
            $id = $favorite->getId()->toString();
            $createdAt = $favorite->getCreatedAt()->format(\DateTimeInterface::ATOM);
        } else {
            $id = $existing->getId()->toString();
            $createdAt = $existing->getCreatedAt()->format(\DateTimeInterface::ATOM);
        }

        return new JsonResponse([
            'id' => $id,
            'placeId' => $placeUuid->toString(),
            'createdAt' => $createdAt,
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/places/{placeId}/favorite', name: 'api_remove_favorite', methods: ['DELETE'])]
    public function removeFavorite(string $placeId, Request $request): JsonResponse
    {
        $this->csrfValidator->validate($request);
        $user = $this->getAuthenticatedUser();

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        $favorite = $this->favoriteRepository->findByUserAndPlace($user->getId(), $placeUuid);
        if (null !== $favorite) {
            $this->favoriteRepository->remove($favorite);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/me/visits', name: 'api_get_visits', methods: ['GET'])]
    public function listVisits(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = min(50, max(1, $request->query->getInt('pageSize', 20)));

        $visits = $this->visitRepository->findByUserId($user->getId(), $page, $pageSize);
        $totalItems = $this->visitRepository->countByUserId($user->getId());
        $totalPages = (int) ceil($totalItems / $pageSize);

        $items = [];
        foreach ($visits as $visit) {
            $items[] = [
                'id' => $visit->getId()->toString(),
                'placeId' => $visit->getPlaceId()->toString(),
                'visitedOn' => $visit->getVisitedOn()->format('Y-m-d'),
                'note' => $visit->getNote(),
                'createdAt' => $visit->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $visit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ]);
    }

    #[Route('/api/v1/places/{placeId}/visits', name: 'api_add_visit', methods: ['POST'])]
    public function addVisit(string $placeId, Request $request): JsonResponse
    {
        $this->csrfValidator->validate($request);
        $user = $this->getAuthenticatedUser();

        try {
            $placeUuid = Uuid::fromString($placeId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid place ID format.');
        }

        if (!$this->placeLookup->existsAndPublished($placeUuid)) {
            return new JsonResponse([
                'title' => 'Not Found',
                'status' => Response::HTTP_NOT_FOUND,
                'detail' => 'Place not found or not published.',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $visitedOnStr = $data['visitedOn'] ?? null;
        $note = $data['note'] ?? null;

        if (null === $visitedOnStr) {
            throw new BadRequestHttpException('Missing visitedOn parameter.');
        }

        try {
            $visitedOn = new \DateTimeImmutable($visitedOnStr);
        } catch (\Throwable) {
            throw new BadRequestHttpException('Invalid visitedOn date format.');
        }

        try {
            $visit = new Visit($user, $placeUuid, $visitedOn, $note, new \DateTimeImmutable());
            $this->visitRepository->save($visit);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'title' => 'Validation Error',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'detail' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'id' => $visit->getId()->toString(),
            'placeId' => $visit->getPlaceId()->toString(),
            'visitedOn' => $visit->getVisitedOn()->format('Y-m-d'),
            'note' => $visit->getNote(),
            'createdAt' => $visit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $visit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/me/visits/{visitId}', name: 'api_update_visit', methods: ['PATCH'])]
    public function updateVisit(string $visitId, Request $request): JsonResponse
    {
        $this->csrfValidator->validate($request);
        $user = $this->getAuthenticatedUser();

        try {
            $visitUuid = Uuid::fromString($visitId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid visit ID format.');
        }

        $visit = $this->visitRepository->findByIdAndUser($visitUuid, $user->getId());
        if (null === $visit) {
            return new JsonResponse([
                'title' => 'Not Found',
                'status' => Response::HTTP_NOT_FOUND,
                'detail' => 'Visit record not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $visitedOnStr = $data['visitedOn'] ?? null;
        $note = $data['note'] ?? null;

        $visitedOn = $visit->getVisitedOn();
        if (null !== $visitedOnStr) {
            try {
                $visitedOn = new \DateTimeImmutable($visitedOnStr);
            } catch (\Throwable) {
                throw new BadRequestHttpException('Invalid visitedOn date format.');
            }
        }

        try {
            $visit->update($visitedOn, $note, new \DateTimeImmutable());
            $this->visitRepository->save($visit);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'title' => 'Validation Error',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'detail' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'id' => $visit->getId()->toString(),
            'placeId' => $visit->getPlaceId()->toString(),
            'visitedOn' => $visit->getVisitedOn()->format('Y-m-d'),
            'note' => $visit->getNote(),
            'createdAt' => $visit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $visit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/me/visits/{visitId}', name: 'api_delete_visit', methods: ['DELETE'])]
    public function deleteVisit(string $visitId, Request $request): JsonResponse
    {
        $this->csrfValidator->validate($request);
        $user = $this->getAuthenticatedUser();

        try {
            $visitUuid = Uuid::fromString($visitId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid visit ID format.');
        }

        $visit = $this->visitRepository->findByIdAndUser($visitUuid, $user->getId());
        if (null !== $visit) {
            $this->visitRepository->remove($visit);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/me/place-state', name: 'api_place_state', methods: ['GET'])]
    public function getPlaceState(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $placeIdsRaw = $request->query->all('placeIds') ?: $request->query->all('placeIds[]');

        if (empty($placeIdsRaw)) {
            return new JsonResponse([]);
        }

        if (count($placeIdsRaw) > 50) {
            throw new BadRequestHttpException('Exceeded maximum of 50 place IDs.');
        }

        $placeIds = [];
        foreach ($placeIdsRaw as $idStr) {
            try {
                $placeIds[] = Uuid::fromString($idStr);
            } catch (\InvalidArgumentException) {
                throw new BadRequestHttpException('Invalid place ID format inside list.');
            }
        }

        $lastVisitedDates = $this->visitRepository->findLastVisitedOnByPlaces($user->getId(), $placeIds);

        $result = [];
        foreach ($placeIds as $uuid) {
            $isFav = null !== $this->favoriteRepository->findByUserAndPlace($user->getId(), $uuid);
            $lastVis = $lastVisitedDates[$uuid->toString()] ?? null;

            $result[$uuid->toString()] = [
                'favorite' => $isFav,
                'lastVisitedOn' => $lastVis,
            ];
        }

        $response = new JsonResponse($result);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
