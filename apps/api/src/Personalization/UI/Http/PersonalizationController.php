<?php

declare(strict_types=1);

namespace App\Personalization\UI\Http;

use App\Identity\UI\Security\CsrfValidator;
use App\Personalization\Application\FavoriteRepository;
use App\Personalization\Application\UseCase\AddFavorite;
use App\Personalization\Application\UseCase\AddVisit;
use App\Personalization\Application\UseCase\DeleteVisit;
use App\Personalization\Application\UseCase\RemoveFavorite;
use App\Personalization\Application\UseCase\UpdateVisit;
use App\Personalization\Application\VisitRepository;
use App\Shared\Application\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PersonalizationController
{
    public function __construct(
        private readonly FavoriteRepository $favoriteRepository,
        private readonly VisitRepository $visitRepository,
        private readonly AddFavorite $addFavoriteUseCase,
        private readonly RemoveFavorite $removeFavoriteUseCase,
        private readonly AddVisit $addVisitUseCase,
        private readonly UpdateVisit $updateVisitUseCase,
        private readonly DeleteVisit $deleteVisitUseCase,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly CsrfValidator $csrfValidator,
        private readonly Clock $clock,
    ) {
    }

    private function getAuthenticatedUser(): \App\Identity\Domain\User
    {
        $user = $this->security->getUser();
        if (!$user instanceof \App\Identity\Domain\User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        if (\App\Identity\Domain\UserStatus::ACTIVE !== $user->status()) {
            throw new AccessDeniedHttpException('User account is not active.');
        }

        return $user;
    }

    private function setPrivateNoCache(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie');
    }

    private function parseDate(string $dateStr): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if (!$date || $date->format('Y-m-d') !== $dateStr) {
            throw new \InvalidArgumentException('Date must be in exact Y-m-d format.');
        }

        return $date->setTime(0, 0, 0);
    }

    #[Route('/api/v1/me/favorites', name: 'api_get_favorites', methods: ['GET'])]
    public function listFavorites(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $input = \App\Personalization\Application\Dto\PaginationInput::fromRequest($request);
        $pageInt = $input->page;
        $pageSizeInt = $input->pageSize;

        $favorites = $this->favoriteRepository->findByUserId($user->getId(), $pageInt, $pageSizeInt);
        $totalItems = $this->favoriteRepository->countByUserId($user->getId());
        $totalPages = (int) ceil($totalItems / $pageSizeInt);

        // Batch load place details to prevent N+1 queries
        $placeIds = array_map(static fn ($fav) => $fav->getPlaceId()->toString(), $favorites);
        $placeDetails = $this->fetchPlacesDetails($placeIds);

        $items = [];
        foreach ($favorites as $fav) {
            $placeIdStr = $fav->getPlaceId()->toString();
            $place = $placeDetails[$placeIdStr] ?? [
                'id' => $placeIdStr,
                'slug' => '',
                'name' => 'Unavailable Place',
                'shortDescription' => '',
                'city' => '',
                'category' => '',
                'ageSummary' => '',
                'coordinates' => ['longitude' => 0.0, 'latitude' => 0.0],
                'published' => false,
            ];

            $items[] = [
                'id' => $fav->getId()->toString(),
                'placeId' => $placeIdStr,
                'createdAt' => $fav->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'place' => $place,
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

        try {
            $favorite = $this->addFavoriteUseCase->execute($user, $placeUuid);
        } catch (\InvalidArgumentException $e) {
            if ('PLACE_NOT_FOUND' === $e->getMessage()) {
                return new JsonResponse([
                    'title' => 'Not Found',
                    'status' => Response::HTTP_NOT_FOUND,
                    'detail' => 'Place not found or not published.',
                ], Response::HTTP_NOT_FOUND);
            }
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $response = new JsonResponse([
            'id' => $favorite->getId()->toString(),
            'placeId' => $favorite->getPlaceId()->toString(),
            'createdAt' => $favorite->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
        $this->setPrivateNoCache($response);

        return $response;
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

        $this->removeFavoriteUseCase->execute($user, $placeUuid);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/visits', name: 'api_get_visits', methods: ['GET'])]
    public function listVisits(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $input = \App\Personalization\Application\Dto\PaginationInput::fromRequest($request);
        $pageInt = $input->page;
        $pageSizeInt = $input->pageSize;

        $visits = $this->visitRepository->findByUserId($user->getId(), $pageInt, $pageSizeInt);
        $totalItems = $this->visitRepository->countByUserId($user->getId());
        $totalPages = (int) ceil($totalItems / $pageSizeInt);

        // Batch load place details to prevent N+1 queries
        $placeIds = array_map(static fn ($visit) => $visit->getPlaceId()->toString(), $visits);
        $placeDetails = $this->fetchPlacesDetails($placeIds);

        $items = [];
        foreach ($visits as $visit) {
            $placeIdStr = $visit->getPlaceId()->toString();
            $place = $placeDetails[$placeIdStr] ?? [
                'id' => $placeIdStr,
                'slug' => '',
                'name' => 'Unavailable Place',
                'shortDescription' => '',
                'city' => '',
                'category' => '',
                'ageSummary' => '',
                'coordinates' => ['longitude' => 0.0, 'latitude' => 0.0],
                'published' => false,
            ];

            $items[] = [
                'id' => $visit->getId()->toString(),
                'placeId' => $placeIdStr,
                'visitedOn' => $visit->getVisitedOn()->format('Y-m-d'),
                'note' => $visit->getNote(),
                'createdAt' => $visit->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $visit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'place' => $place,
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

        $input = \App\Personalization\Application\Dto\CreateVisitInput::fromRequest($request);
        $visitedOn = $input->visitedOn;
        $note = $input->note;

        try {
            $visit = $this->addVisitUseCase->execute($user, $placeUuid, $visitedOn, $note);
        } catch (\InvalidArgumentException $e) {
            if ('PLACE_NOT_FOUND' === $e->getMessage()) {
                return new JsonResponse([
                    'title' => 'Not Found',
                    'status' => Response::HTTP_NOT_FOUND,
                    'detail' => 'Place not found or not published.',
                ], Response::HTTP_NOT_FOUND);
            }
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $response = new JsonResponse([
            'id' => $visit->getId()->toString(),
            'placeId' => $visit->getPlaceId()->toString(),
            'visitedOn' => $visit->getVisitedOn()->format('Y-m-d'),
            'note' => $visit->getNote(),
            'createdAt' => $visit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $visit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
        $this->setPrivateNoCache($response);

        return $response;
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

        $input = \App\Personalization\Application\Dto\UpdateVisitInput::fromRequest($request);
        $visitedOn = $input->visitedOn;
        $hasVisitedOn = $input->hasVisitedOn;
        $note = $input->note;
        $hasNote = $input->hasNote;

        try {
            $visit = $this->updateVisitUseCase->execute(
                $user,
                $visitUuid,
                $visitedOn,
                $hasVisitedOn,
                $note,
                $hasNote
            );
        } catch (\InvalidArgumentException $e) {
            if ('VISIT_NOT_FOUND' === $e->getMessage()) {
                return new JsonResponse([
                    'title' => 'Not Found',
                    'status' => Response::HTTP_NOT_FOUND,
                    'detail' => 'Visit record not found.',
                ], Response::HTTP_NOT_FOUND);
            }
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $response = new JsonResponse([
            'id' => $visit->getId()->toString(),
            'placeId' => $visit->getPlaceId()->toString(),
            'visitedOn' => $visit->getVisitedOn()->format('Y-m-d'),
            'note' => $visit->getNote(),
            'createdAt' => $visit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $visit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
        $this->setPrivateNoCache($response);

        return $response;
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

        $this->deleteVisitUseCase->execute($user, $visitUuid);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $this->setPrivateNoCache($response);

        return $response;
    }

    #[Route('/api/v1/me/place-state', name: 'api_place_state', methods: ['GET'])]
    public function getPlaceState(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $input = \App\Personalization\Application\Dto\PlaceStateInput::fromRequest($request);
        $placeIds = $input->placeIds;

        if (empty($placeIds)) {
            $response = new JsonResponse([]);
            $this->setPrivateNoCache($response);

            return $response;
        }

        // Optimized batch query: only 2 queries total instead of N+1
        $lastVisitedDates = $this->visitRepository->findLastVisitedOnByPlaces($user->getId(), $placeIds);
        $favorites = $this->favoriteRepository->findFavoritesByPlaces($user->getId(), $placeIds);

        $favoritePlaceIds = [];
        foreach ($favorites as $fav) {
            $favoritePlaceIds[$fav->getPlaceId()->toString()] = true;
        }

        $result = [];
        foreach ($placeIds as $uuid) {
            $uuidStr = $uuid->toString();
            $isFav = isset($favoritePlaceIds[$uuidStr]);
            $lastVis = $lastVisitedDates[$uuidStr] ?? null;

            $result[$uuidStr] = [
                'favorite' => $isFav,
                'lastVisitedOn' => $lastVis,
            ];
        }

        $response = new JsonResponse($result);
        $this->setPrivateNoCache($response);

        return $response;
    }

    /**
     * Helper to load place cards details in a single query.
     *
     * @param list<string> $placeIds
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchPlacesDetails(array $placeIds): array
    {
        if (empty($placeIds)) {
            return [];
        }

        $sql = 'SELECT p.id, p.slug, p.name, p.short_description, p.status, c.name AS city,
                COALESCE((SELECT json_agg(json_build_object(\'slug\', cat.slug, \'name\', cat.name) ORDER BY cat.display_order) FROM place_categories pc JOIN categories cat ON cat.id = pc.category_id WHERE pc.place_id = p.id), \'[]\'::json) AS categories,
                (SELECT MIN(min_age_months) FROM place_age_zones paz WHERE paz.place_id = p.id) AS min_age_months,
                (SELECT MAX(max_age_months) FROM place_age_zones paz WHERE paz.place_id = p.id) AS max_age_months,
                p.longitude, p.latitude,
                (SELECT variants FROM place_photos WHERE place_id = p.id AND is_main = true AND status = \'COMPLETED\' LIMIT 1) AS main_photo_variants
            FROM places p JOIN cities c ON c.id = p.city_id
            WHERE p.id IN (:place_ids)';

        $rows = $this->connection->fetchAllAssociative($sql, [
            'place_ids' => $placeIds,
        ], [
            'place_ids' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ]);

        $details = [];
        foreach ($rows as $row) {
            $categories = json_decode($row['categories'], true) ?: [];
            $categoryName = !empty($categories) ? $categories[0]['name'] : '';

            $minAge = null !== $row['min_age_months'] ? (int) $row['min_age_months'] : null;
            $maxAge = null !== $row['max_age_months'] ? (int) $row['max_age_months'] : null;
            $ageSummary = '';
            if (null !== $minAge && null !== $maxAge) {
                if ($minAge === $maxAge) {
                    $ageSummary = $minAge.' m.';
                } else {
                    $ageSummary = $minAge.'-'.$maxAge.' m.';
                }
            }

            $mainPhoto = null;
            if (isset($row['main_photo_variants']) && \is_string($row['main_photo_variants'])) {
                $mainPhoto = json_decode($row['main_photo_variants'], true);
            }

            $details[$row['id']] = [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'shortDescription' => $row['short_description'],
                'city' => $row['city'],
                'category' => $categoryName,
                'ageSummary' => $ageSummary,
                'coordinates' => [
                    'longitude' => (float) $row['longitude'],
                    'latitude' => (float) $row['latitude'],
                ],
                'published' => 'published' === $row['status'],
                'main_photo' => $mainPhoto,
            ];
        }

        return $details;
    }
}
