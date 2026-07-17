<?php

declare(strict_types=1);

namespace App\Discovery\UI\Http;

use App\Discovery\Application\Dto\PaginationMetadata;
use App\Discovery\Application\PlaceReadModel;
use App\Discovery\Application\PlaceSearchQuery;
use App\Shared\Application\CorrelationId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscoveryController
{
    public function __construct(
        private PlaceReadModel $places,
        #[Autowire(service: 'limiter.place_search')] private RateLimiterFactory $searchLimiter,
        #[Autowire(service: 'limiter.map_places')] private RateLimiterFactory $mapLimiter,
    ) {
    }

    #[Route('/api/v1/cities', name: 'api_cities', methods: ['GET'])]
    public function cities(): JsonResponse
    {
        return $this->collection($this->places->referenceData('cities'));
    }

    #[Route('/api/v1/categories', name: 'api_categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        return $this->collection($this->places->referenceData('categories'));
    }

    #[Route('/api/v1/amenities', name: 'api_amenities', methods: ['GET'])]
    public function amenities(): JsonResponse
    {
        return $this->collection($this->places->referenceData('amenities'));
    }

    #[Route('/api/v1/places', name: 'api_places', methods: ['GET'])]
    public function places(Request $request): JsonResponse
    {
        if ($limited = $this->limit($request, $this->searchLimiter)) {
            return $limited;
        }
        $query = PlaceSearchQuery::fromRequest($request);
        $result = $this->places->search($query);
        $totalPages = 0 === $result['total'] ? 0 : (int) ceil($result['total'] / $query->pageSize);

        return new JsonResponse(['items' => $result['items'], 'pagination' => new PaginationMetadata($query->page, $query->pageSize, $result['total'], $totalPages), 'meta' => ['sort' => $query->sort]]);
    }

    #[Route('/api/v1/places/{slug}', name: 'api_place_details', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function details(string $slug, Request $request): JsonResponse
    {
        $place = $this->places->details($slug);
        if (null === $place) {
            return self::problem($request, 404, 'place_not_found', 'Place not found.');
        }

        return new JsonResponse($place->jsonSerialize());
    }

    #[Route('/api/v1/map/places', name: 'api_map_places', methods: ['GET'])]
    public function map(Request $request): JsonResponse
    {
        if ($limited = $this->limit($request, $this->mapLimiter)) {
            return $limited;
        }
        $west = self::requiredFloat($request, 'west', -180, 180);
        $south = self::requiredFloat($request, 'south', -90, 90);
        $east = self::requiredFloat($request, 'east', -180, 180);
        $north = self::requiredFloat($request, 'north', -90, 90);
        $zoom = self::requiredFloat($request, 'zoom', 0, 24);
        if ($west >= $east || $south >= $north) {
            throw new \InvalidArgumentException('Bounding box must not cross the antimeridian and must have increasing axes.');
        }
        if (($east - $west) * ($north - $south) > 25 || $zoom < 4) {
            throw new \InvalidArgumentException('Bounding box is too large.');
        }
        $result = $this->places->map($west, $south, $east, $north, PlaceSearchQuery::fromRequest($request));

        return new JsonResponse(['type' => 'FeatureCollection', 'features' => $result['features'], 'truncated' => $result['truncated']]);
    }

    /** @param list<array<string, mixed>> $items */
    private function collection(array $items): JsonResponse
    {
        return new JsonResponse(['items' => $items, 'pagination' => ['page' => 1, 'pageSize' => \count($items), 'totalItems' => \count($items), 'totalPages' => 1], 'meta' => []]);
    }

    private function limit(Request $request, RateLimiterFactory $factory): ?JsonResponse
    {
        $limit = $factory->create($request->getClientIp() ?? 'unknown')->consume();
        if ($limit->isAccepted()) {
            return null;
        }
        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        return self::problem($request, 429, 'rate_limit_exceeded', 'Too many requests.', ['Retry-After' => (string) $retryAfter]);
    }

    /** @param array<string, string> $headers */
    private static function problem(Request $request, int $status, string $code, string $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse(['type' => 'https://familyplaces.example/problems/'.$code, 'title' => $detail, 'status' => $status, 'detail' => $detail, 'code' => $code, 'correlationId' => $request->attributes->get(CorrelationId::ATTRIBUTE)], $status, ['Content-Type' => 'application/problem+json', ...$headers]);
    }

    private static function requiredFloat(Request $request, string $name, float $min, float $max): float
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException($name.' is required and must be numeric.');
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException($name.' is out of range.');
        }

        return $number;
    }
}
