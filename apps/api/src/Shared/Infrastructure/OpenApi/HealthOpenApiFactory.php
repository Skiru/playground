<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final class HealthOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $openApi->getPaths()->addPath('/api/v1/health/live', new PathItem(
            get: new Operation(
                operationId: 'getHealthLive',
                tags: ['Health'],
                responses: ['200' => new Response('The API process is alive.')],
                summary: 'Liveness probe',
                description: 'Checks process availability without contacting dependencies.',
                security: [],
            ),
        ));
        $openApi->getPaths()->addPath('/api/v1/health/ready', new PathItem(
            get: new Operation(
                operationId: 'getHealthReady',
                tags: ['Health'],
                responses: [
                    '200' => new Response('The API and required database are ready.'),
                    '503' => new Response('The required database dependency is unavailable.'),
                ],
                summary: 'Readiness probe',
                description: 'Checks the required PostgreSQL dependency; optional Redis is excluded.',
                security: [],
            ),
        ));
        foreach (['cities', 'categories', 'amenities'] as $collection) {
            $openApi->getPaths()->addPath('/api/v1/'.$collection, new PathItem(get: new Operation(
                operationId: 'get'.ucfirst($collection), tags: ['Discovery'], responses: self::responses('Reference collection.', self::collectionSchema($collection), invalidQuery: false, rateLimited: false), summary: 'List enabled '.$collection, security: [],
            )));
        }
        $searchParameters = [
            self::parameter('city'), self::parameter('category'), self::parameter('ageMonths', 'integer'),
            self::parameter('latitude', 'number'), self::parameter('longitude', 'number'), self::parameter('radiusKm', 'number'),
            self::parameter('amenities', 'array'), self::parameter('indoor', 'boolean'), self::parameter('outdoor', 'boolean'),
            self::parameter('freeEntry', 'boolean'), self::parameter('openNow', 'boolean'), self::parameter('q'),
            self::parameter('page', 'integer'), self::parameter('pageSize', 'integer'), self::parameter('sort'),
        ];
        $openApi->getPaths()->addPath('/api/v1/places', new PathItem(get: new Operation(
            operationId: 'searchPlaces', tags: ['Discovery'], parameters: $searchParameters, responses: self::responses('Paginated place cards.', self::searchSchema()), summary: 'Search published places', description: 'Filters published places. Amenities use AND semantics. Radius is 1-100 km and page size is at most 50.', security: [],
        )));
        $openApi->getPaths()->addPath('/api/v1/places/{slug}', new PathItem(get: new Operation(
            operationId: 'getPlaceBySlug', tags: ['Discovery'], parameters: [new Parameter('slug', 'path', 'Published place slug.', true, schema: ['type' => 'string'])], responses: self::responses('Published place details.', self::placeSchema(), invalidQuery: false, rateLimited: false, notFound: true), summary: 'Get published place details', security: [],
        )));
        $openApi->getPaths()->addPath('/api/v1/map/places', new PathItem(get: new Operation(
            operationId: 'getMapPlaces', tags: ['Discovery'], parameters: [self::parameter('west', 'number', true), self::parameter('south', 'number', true), self::parameter('east', 'number', true), self::parameter('north', 'number', true), self::parameter('zoom', 'number', true), ...$searchParameters], responses: self::responses('GeoJSON FeatureCollection, at most 500 features.', self::mapSchema()), summary: 'List published places in a map bounding box', description: 'Rejects antimeridian crossing and oversized bounding boxes.', security: [],
        )));

        return $openApi;
    }

    private static function parameter(string $name, string $type = 'string', bool $required = false): Parameter
    {
        $schema = ['type' => $type];
        if ('array' === $type) {
            $schema['items'] = ['type' => 'string'];
        }
        $schema += match ($name) {
            'page' => ['minimum' => 1, 'maximum' => 5000],
            'pageSize' => ['minimum' => 1, 'maximum' => 50],
            'q' => ['maxLength' => 100],
            'amenities' => ['maxItems' => 10],
            default => [],
        };

        return new Parameter($name, 'query', $name.' filter.', $required, schema: $schema);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<int, Response>
     */
    private static function responses(string $success, array $schema, bool $invalidQuery = true, bool $rateLimited = true, bool $notFound = false): array
    {
        $problem = new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]]);
        $responses = ['200' => new Response($success, new \ArrayObject(['application/json' => ['schema' => $schema]]))];
        if ($invalidQuery) {
            $responses['400'] = new Response('RFC 9457 invalid_query Problem Details.', $problem);
        }
        if ($rateLimited) {
            $responses['429'] = new Response('RFC 9457 rate_limit_exceeded Problem Details with Retry-After.', $problem);
        }
        if ($notFound) {
            $responses['404'] = new Response('RFC 9457 place_not_found Problem Details.', $problem);
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    private static function collectionSchema(string $collection): array
    {
        $properties = ['id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string'], 'slug' => ['type' => 'string']];
        if ('cities' === $collection) {
            $properties += ['country_code' => ['type' => 'string'], 'default_zoom' => ['type' => 'integer'], 'default_radius_km' => ['type' => 'integer'], 'timezone' => ['type' => 'string']];
        } else {
            $properties += ['icon_key' => ['type' => 'string'], 'display_order' => ['type' => 'integer']];
        }

        return ['type' => 'object', 'required' => ['items', 'pagination', 'meta'], 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties]], 'pagination' => self::paginationSchema(), 'meta' => ['type' => 'object']]];
    }

    /** @return array<string, mixed> */
    private static function searchSchema(): array
    {
        return ['type' => 'object', 'required' => ['items', 'pagination', 'meta'], 'additionalProperties' => false, 'properties' => ['items' => ['type' => 'array', 'items' => self::listItemSchema()], 'pagination' => self::paginationSchema(), 'meta' => ['type' => 'object', 'required' => ['sort'], 'properties' => ['sort' => ['type' => 'string']]]]];
    }

    /** @return array<string, mixed> */
    private static function listItemSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'slug', 'name', 'short_description', 'city', 'categories', 'min_age_months', 'max_age_months', 'indoor', 'outdoor', 'free_entry', 'verification_status', 'amenities', 'distance_meters', 'longitude', 'latitude', 'is_open_now', 'complete', 'relevance_score'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'slug' => ['type' => 'string'], 'name' => ['type' => 'string'], 'short_description' => ['type' => 'string'], 'city' => ['type' => 'string'], 'categories' => self::namedItemsSchema(), 'min_age_months' => ['type' => 'integer'], 'max_age_months' => ['type' => ['integer', 'null']], 'indoor' => ['type' => 'boolean'], 'outdoor' => ['type' => 'boolean'], 'free_entry' => ['type' => 'boolean'], 'verification_status' => ['type' => 'string'], 'amenities' => self::namedItemsSchema(), 'distance_meters' => ['type' => ['number', 'null']], 'longitude' => ['type' => 'number'], 'latitude' => ['type' => 'number'], 'is_open_now' => ['type' => ['boolean', 'null']], 'complete' => ['type' => 'boolean'], 'relevance_score' => ['type' => 'number']]];
    }

    /** @return array<string, mixed> */
    private static function placeSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'slug', 'name', 'short_description', 'description', 'city_name', 'city_slug', 'address_line1', 'address_line2', 'postal_code', 'country_code', 'categories', 'amenities', 'age_zones', 'weekly_opening', 'special_opening', 'indoor', 'outdoor', 'free_entry', 'price_description', 'website_url', 'phone', 'verification_status', 'longitude', 'latitude'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'slug' => ['type' => 'string'], 'name' => ['type' => 'string'], 'short_description' => ['type' => 'string'], 'description' => ['type' => 'string'], 'city_name' => ['type' => 'string'], 'city_slug' => ['type' => 'string'], 'address_line1' => ['type' => 'string'], 'address_line2' => ['type' => ['string', 'null']], 'postal_code' => ['type' => 'string'], 'country_code' => ['type' => 'string'], 'categories' => self::namedItemsSchema(), 'amenities' => self::namedItemsSchema(), 'age_zones' => ['type' => 'array', 'items' => ['type' => 'object']], 'weekly_opening' => ['type' => 'array', 'items' => ['type' => 'object']], 'special_opening' => ['type' => 'array', 'items' => ['type' => 'object']], 'indoor' => ['type' => 'boolean'], 'outdoor' => ['type' => 'boolean'], 'free_entry' => ['type' => 'boolean'], 'price_description' => ['type' => ['string', 'null']], 'website_url' => ['type' => ['string', 'null']], 'phone' => ['type' => ['string', 'null']], 'verification_status' => ['type' => 'string'], 'longitude' => ['type' => 'number'], 'latitude' => ['type' => 'number']]];
    }

    /** @return array<string, mixed> */
    private static function namedItemsSchema(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['slug', 'name'], 'properties' => ['slug' => ['type' => 'string'], 'name' => ['type' => 'string']]]];
    }

    /** @return array<string, mixed> */
    private static function mapSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'features', 'truncated'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['FeatureCollection']],
                'truncated' => ['type' => 'boolean'],
                'features' => [
                    'type' => 'array',
                    'maxItems' => 500,
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'geometry', 'properties'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['Feature']],
                            'geometry' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string'],
                                    'coordinates' => ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2],
                                ],
                            ],
                            'properties' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['slug', 'name', 'indoor', 'outdoor', 'freeEntry'], 'properties' => ['slug' => ['type' => 'string'], 'name' => ['type' => 'string'], 'indoor' => ['type' => 'boolean'], 'outdoor' => ['type' => 'boolean'], 'freeEntry' => ['type' => 'boolean']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function paginationSchema(): array
    {
        return ['type' => 'object', 'required' => ['page', 'pageSize', 'totalItems', 'totalPages'], 'properties' => ['page' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer'], 'totalItems' => ['type' => 'integer'], 'totalPages' => ['type' => 'integer']]];
    }

    /** @return array<string, mixed> */
    private static function problemSchema(): array
    {
        return ['type' => 'object', 'required' => ['type', 'title', 'status', 'detail', 'code', 'correlationId'], 'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'], 'detail' => ['type' => 'string'], 'code' => ['type' => 'string'], 'correlationId' => ['type' => 'string', 'format' => 'uuid']]];
    }
}
