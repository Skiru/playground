<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\RequestBody;
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

        // 1. POST /api/v1/auth/google
        $openApi->getPaths()->addPath('/api/v1/auth/google', new PathItem(
            post: new Operation(
                operationId: 'loginWithGoogle',
                tags: ['Authentication'],
                requestBody: new \ApiPlatform\OpenApi\Model\RequestBody('Google ID token.', new \ArrayObject([
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['credential'],
                            'properties' => [
                                'credential' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ])),
                responses: [
                    '200' => new Response('Successfully authenticated.', new \ArrayObject(['application/json' => ['schema' => self::sessionSchema()]])),
                    '401' => new Response('Invalid Google credential.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                    '409' => new Response('Account link required.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Authenticate with Google ID token',
                security: [],
            ),
        ));

        // 2. POST /api/v1/dev-auth/login
        $openApi->getPaths()->addPath('/api/v1/dev-auth/login', new PathItem(
            post: new Operation(
                operationId: 'loginWithDevAuth',
                tags: ['Authentication'],
                responses: [
                    '200' => new Response('Successfully authenticated.', new \ArrayObject(['application/json' => ['schema' => self::sessionSchema()]])),
                    '404' => new Response('Not found.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Dev bypass login',
                security: [],
            ),
        ));

        // 3. PUT /api/v1/places/{placeId}/favorite
        $openApi->getPaths()->addPath('/api/v1/places/{placeId}/favorite', new PathItem(
            put: new Operation(
                operationId: 'addFavorite',
                tags: ['Personalization'],
                parameters: [new Parameter('placeId', 'path', 'Place UUID.', true, schema: ['type' => 'string', 'format' => 'uuid'])],
                responses: [
                    '200' => new Response('Successfully favorited.', new \ArrayObject(['application/json' => ['schema' => self::favoriteSchema()]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                    '404' => new Response('Place not found.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Add place to favorites',
            ),
            delete: new Operation(
                operationId: 'removeFavorite',
                tags: ['Personalization'],
                parameters: [new Parameter('placeId', 'path', 'Place UUID.', true, schema: ['type' => 'string', 'format' => 'uuid'])],
                responses: [
                    '204' => new Response('Successfully removed.'),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Remove place from favorites',
            )
        ));

        // 4. GET /api/v1/me/favorites
        $openApi->getPaths()->addPath('/api/v1/me/favorites', new PathItem(
            get: new Operation(
                operationId: 'listFavorites',
                tags: ['Personalization'],
                parameters: [
                    new Parameter('page', 'query', 'Page number.', false, schema: ['type' => 'integer', 'default' => 1]),
                    new Parameter('pageSize', 'query', 'Page size.', false, schema: ['type' => 'integer', 'default' => 20]),
                ],
                responses: [
                    '200' => new Response('Successfully listed.', new \ArrayObject(['application/json' => ['schema' => self::paginatedFavoritesSchema()]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'List favorite places',
            )
        ));

        // 5. POST /api/v1/places/{placeId}/visits
        $openApi->getPaths()->addPath('/api/v1/places/{placeId}/visits', new PathItem(
            post: new Operation(
                operationId: 'addVisit',
                tags: ['Personalization'],
                parameters: [new Parameter('placeId', 'path', 'Place UUID.', true, schema: ['type' => 'string', 'format' => 'uuid'])],
                requestBody: new \ApiPlatform\OpenApi\Model\RequestBody('Visit details.', new \ArrayObject([
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['visitedOn'],
                            'properties' => [
                                'visitedOn' => ['type' => 'string', 'format' => 'date'],
                                'note' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                            ],
                        ],
                    ],
                ])),
                responses: [
                    '201' => new Response('Successfully created visit.', new \ArrayObject(['application/json' => ['schema' => self::visitSchema()]])),
                    '400' => new Response('Invalid request payload.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                    '404' => new Response('Place not found.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Record a visit to a place',
            )
        ));

        // 6. GET /api/v1/me/visits
        $openApi->getPaths()->addPath('/api/v1/me/visits', new PathItem(
            get: new Operation(
                operationId: 'listVisits',
                tags: ['Personalization'],
                parameters: [
                    new Parameter('page', 'query', 'Page number.', false, schema: ['type' => 'integer', 'default' => 1]),
                    new Parameter('pageSize', 'query', 'Page size.', false, schema: ['type' => 'integer', 'default' => 20]),
                ],
                responses: [
                    '200' => new Response('Successfully listed.', new \ArrayObject(['application/json' => ['schema' => self::paginatedVisitsSchema()]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'List recorded visits',
            )
        ));

        // 7. PATCH /api/v1/me/visits/{visitId}
        $openApi->getPaths()->addPath('/api/v1/me/visits/{visitId}', new PathItem(
            patch: new Operation(
                operationId: 'updateVisit',
                tags: ['Personalization'],
                parameters: [new Parameter('visitId', 'path', 'Visit UUID.', true, schema: ['type' => 'string', 'format' => 'uuid'])],
                requestBody: new \ApiPlatform\OpenApi\Model\RequestBody('Updated visit details.', new \ArrayObject([
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'visitedOn' => ['type' => 'string', 'format' => 'date'],
                                'note' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                            ],
                        ],
                    ],
                ])),
                responses: [
                    '200' => new Response('Successfully updated.', new \ArrayObject(['application/json' => ['schema' => self::visitSchema()]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                    '404' => new Response('Visit not found.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Update a recorded visit',
            ),
            delete: new Operation(
                operationId: 'deleteVisit',
                tags: ['Personalization'],
                parameters: [new Parameter('visitId', 'path', 'Visit UUID.', true, schema: ['type' => 'string', 'format' => 'uuid'])],
                responses: [
                    '204' => new Response('Successfully deleted.'),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                    '404' => new Response('Visit not found.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Delete a recorded visit',
            )
        ));

        // 8. GET /api/v1/me/place-state
        $openApi->getPaths()->addPath('/api/v1/me/place-state', new PathItem(
            get: new Operation(
                operationId: 'getPlaceState',
                tags: ['Personalization'],
                parameters: [
                    new Parameter('placeIds[]', 'query', 'Array of place UUIDs.', false, schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 50]),
                ],
                responses: [
                    '200' => new Response('Successfully retrieved state.', new \ArrayObject(['application/json' => ['schema' => self::placeStateSchema()]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Get favorite and visit states for a batch of places',
            )
        ));

        // 9. GET & POST /api/v1/places/{placeId}/reviews
        $openApi->getPaths()->addPath('/api/v1/places/{placeId}/reviews', new PathItem(
            get: new Operation(
                operationId: 'listReviews',
                tags: ['Community'],
                parameters: [
                    new Parameter('placeId', 'path', 'Place UUID.', true, schema: ['type' => 'string', 'format' => 'uuid']),
                    new Parameter('page', 'query', 'Page number.', false, schema: ['type' => 'integer', 'minimum' => 1]),
                    new Parameter('pageSize', 'query', 'Page size.', false, schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]),
                    new Parameter('sort', 'query', 'Sort parameter.', false, schema: ['type' => 'string', 'enum' => ['newest', 'highest', 'lowest']]),
                ],
                responses: [
                    '200' => new Response('List of reviews.', new \ArrayObject(['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['summary', 'items', 'pagination'],
                        'properties' => [
                            'summary' => ['type' => 'object', 'required' => ['averageRating', 'totalReviews', 'histogram'], 'properties' => [
                                'averageRating' => ['type' => 'number'],
                                'totalReviews' => ['type' => 'integer'],
                                'histogram' => ['type' => 'object'],
                            ]],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'pagination' => ['type' => 'object'],
                        ]
                    ]]])),
                ],
                summary: 'Get reviews for a place',
            ),
            post: new Operation(
                operationId: 'addReview',
                tags: ['Community'],
                parameters: [
                    new Parameter('placeId', 'path', 'Place UUID.', true, schema: ['type' => 'string', 'format' => 'uuid']),
                ],
                requestBody: new RequestBody('Review body', new \ArrayObject(['application/json' => ['schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['rating', 'body'],
                    'properties' => [
                        'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                        'body' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 5000],
                        'visitedOn' => ['type' => ['string', 'null'], 'format' => 'date'],
                    ]
                ]]])),
                responses: [
                    '201' => new Response('Review created.', new \ArrayObject(['application/json' => ['schema' => ['type' => 'object']]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Add review for a place',
            )
        ));

        // 10. PATCH & DELETE /api/v1/me/reviews/{reviewId}
        $openApi->getPaths()->addPath('/api/v1/me/reviews/{reviewId}', new PathItem(
            patch: new Operation(
                operationId: 'updateReview',
                tags: ['Community'],
                parameters: [
                    new Parameter('reviewId', 'path', 'Review UUID.', true, schema: ['type' => 'string', 'format' => 'uuid']),
                ],
                requestBody: new RequestBody('Update review body', new \ArrayObject(['application/json' => ['schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['version'],
                    'properties' => [
                        'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                        'body' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 5000],
                        'visitedOn' => ['type' => ['string', 'null'], 'format' => 'date'],
                        'version' => ['type' => 'integer'],
                    ]
                ]]])),
                responses: [
                    '200' => new Response('Review updated.', new \ArrayObject(['application/json' => ['schema' => ['type' => 'object']]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Update review',
            ),
            delete: new Operation(
                operationId: 'deleteReview',
                tags: ['Community'],
                parameters: [
                    new Parameter('reviewId', 'path', 'Review UUID.', true, schema: ['type' => 'string', 'format' => 'uuid']),
                ],
                responses: [
                    '204' => new Response('Success (no content)'),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Delete review',
            )
        ));

        // 11. GET /api/v1/me/reviews
        $openApi->getPaths()->addPath('/api/v1/me/reviews', new PathItem(
            get: new Operation(
                operationId: 'myReviews',
                tags: ['Community'],
                parameters: [
                    new Parameter('page', 'query', 'Page number.', false, schema: ['type' => 'integer', 'minimum' => 1]),
                    new Parameter('pageSize', 'query', 'Page size.', false, schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]),
                ],
                responses: [
                    '200' => new Response('My reviews list.', new \ArrayObject(['application/json' => ['schema' => ['type' => 'object']]])),
                    '401' => new Response('Unauthorized.', new \ArrayObject(['application/problem+json' => ['schema' => self::problemSchema()]])),
                ],
                summary: 'Get my reviews',
            )
        ));

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
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'slug', 'name', 'short_description', 'city', 'categories', 'min_age_months', 'max_age_months', 'indoor', 'outdoor', 'free_entry', 'verification_status', 'amenities', 'distance_meters', 'longitude', 'latitude', 'is_open_now', 'complete', 'relevance_score', 'average_rating', 'total_reviews'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'slug' => ['type' => 'string'], 'name' => ['type' => 'string'], 'short_description' => ['type' => 'string'], 'city' => ['type' => 'string'], 'categories' => self::namedItemsSchema(), 'min_age_months' => ['type' => 'integer'], 'max_age_months' => ['type' => ['integer', 'null']], 'indoor' => ['type' => 'boolean'], 'outdoor' => ['type' => 'boolean'], 'free_entry' => ['type' => 'boolean'], 'verification_status' => ['type' => 'string'], 'amenities' => self::namedItemsSchema(), 'distance_meters' => ['type' => ['number', 'null']], 'longitude' => ['type' => 'number'], 'latitude' => ['type' => 'number'], 'is_open_now' => ['type' => ['boolean', 'null']], 'complete' => ['type' => 'boolean'], 'relevance_score' => ['type' => 'number'], 'average_rating' => ['type' => 'number'], 'total_reviews' => ['type' => 'integer'], 'main_photo' => ['type' => ['object', 'null'], 'properties' => ['thumbnail_mini' => ['type' => 'string'], 'thumbnail' => ['type' => 'string'], 'card' => ['type' => 'string'], 'hero' => ['type' => 'string'], 'original_max' => ['type' => 'string']]]]];
    }

    /** @return array<string, mixed> */
    private static function placeSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'id', 'slug', 'name', 'short_description', 'description', 'city_name', 'city_slug',
                'address_line1', 'address_line2', 'postal_code', 'country_code', 'categories', 'amenities',
                'age_zones', 'weekly_opening', 'special_opening', 'indoor', 'outdoor', 'free_entry',
                'price_description', 'website_url', 'phone', 'verification_status', 'longitude', 'latitude',
                'ageZones', 'openingSchedule', 'specialOpeningDays'
            ],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'short_description' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'city_name' => ['type' => 'string'],
                'city_slug' => ['type' => 'string'],
                'address_line1' => ['type' => 'string'],
                'address_line2' => ['type' => ['string', 'null']],
                'postal_code' => ['type' => 'string'],
                'country_code' => ['type' => 'string'],
                'categories' => self::namedItemsSchema(),
                'amenities' => self::namedItemsSchema(),
                'age_zones' => ['type' => 'array', 'items' => ['type' => 'object']],
                'weekly_opening' => ['type' => 'array', 'items' => ['type' => 'object']],
                'special_opening' => ['type' => 'array', 'items' => ['type' => 'object']],
                'ageZones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['minAgeMonths', 'maxAgeMonths', 'label'],
                        'properties' => [
                            'minAgeMonths' => ['type' => 'integer'],
                            'maxAgeMonths' => ['type' => ['integer', 'null']],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
                'openingSchedule' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['dayOfWeek', 'periods', 'closed'],
                        'properties' => [
                            'dayOfWeek' => ['type' => 'integer'],
                            'closed' => ['type' => 'boolean'],
                            'periods' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['opensAt', 'closesAt', 'closesNextDay'],
                                    'properties' => [
                                        'opensAt' => ['type' => 'string'],
                                        'closesAt' => ['type' => 'string'],
                                        'closesNextDay' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'specialOpeningDays' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['date', 'mode', 'periods', 'note'],
                        'properties' => [
                            'date' => ['type' => 'string'],
                            'mode' => ['type' => 'string'],
                            'note' => ['type' => ['string', 'null']],
                            'periods' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['opensAt', 'closesAt', 'closesNextDay'],
                                    'properties' => [
                                        'opensAt' => ['type' => 'string'],
                                        'closesAt' => ['type' => 'string'],
                                        'closesNextDay' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'indoor' => ['type' => 'boolean'],
                'outdoor' => ['type' => 'boolean'],
                'free_entry' => ['type' => 'boolean'],
                'price_description' => ['type' => ['string', 'null']],
                'website_url' => ['type' => ['string', 'null']],
                'phone' => ['type' => ['string', 'null']],
                'verification_status' => ['type' => 'string'],
                'longitude' => ['type' => 'number'],
                'latitude' => ['type' => 'number'],
                'main_photo' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'thumbnail_mini' => ['type' => 'string'],
                        'thumbnail' => ['type' => 'string'],
                        'card' => ['type' => 'string'],
                        'hero' => ['type' => 'string'],
                        'original_max' => ['type' => 'string'],
                    ],
                ],
                'photos' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'is_main' => ['type' => 'boolean'],
                            'alt_text' => ['type' => ['string', 'null']],
                            'caption' => ['type' => ['string', 'null']],
                            'variants' => [
                                'type' => 'object',
                                'properties' => [
                                    'thumbnail_mini' => ['type' => 'string'],
                                    'thumbnail' => ['type' => 'string'],
                                    'card' => ['type' => 'string'],
                                    'hero' => ['type' => 'string'],
                                    'original_max' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
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

    /** @return array<string, mixed> */
    private static function sessionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['authenticated', 'user', 'csrfToken'],
            'properties' => [
                'authenticated' => ['type' => 'boolean'],
                'user' => [
                    'type' => ['object', 'null'],
                    'required' => ['id', 'displayName', 'initials', 'roles'],
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'displayName' => ['type' => 'string'],
                        'initials' => ['type' => 'string'],
                        'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'csrfToken' => ['type' => ['string', 'null']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function favoriteSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'placeId', 'createdAt'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'placeId' => ['type' => 'string', 'format' => 'uuid'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function visitSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'placeId', 'visitedOn', 'note', 'createdAt', 'updatedAt'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'placeId' => ['type' => 'string', 'format' => 'uuid'],
                'visitedOn' => ['type' => 'string', 'format' => 'date'],
                'note' => ['type' => ['string', 'null']],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function favoriteCardSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'placeId', 'createdAt', 'place'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'placeId' => ['type' => 'string', 'format' => 'uuid'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'place' => self::placeCardSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function visitCardSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'placeId', 'visitedOn', 'note', 'createdAt', 'updatedAt', 'place'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'placeId' => ['type' => 'string', 'format' => 'uuid'],
                'visitedOn' => ['type' => 'string', 'format' => 'date'],
                'note' => ['type' => ['string', 'null']],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                'place' => self::placeCardSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function placeCardSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'slug', 'name', 'shortDescription', 'city', 'category', 'ageSummary', 'coordinates', 'published'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'shortDescription' => ['type' => 'string'],
                'city' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'ageSummary' => ['type' => 'string'],
                'coordinates' => [
                    'type' => 'object',
                    'required' => ['longitude', 'latitude'],
                    'properties' => [
                        'longitude' => ['type' => 'number'],
                        'latitude' => ['type' => 'number'],
                    ],
                ],
                'published' => ['type' => 'boolean'],
                'main_photo' => ['type' => ['object', 'null'], 'properties' => ['thumbnail_mini' => ['type' => 'string'], 'thumbnail' => ['type' => 'string'], 'card' => ['type' => 'string'], 'hero' => ['type' => 'string'], 'original_max' => ['type' => 'string']]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function paginatedFavoritesSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['items', 'pagination'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => self::favoriteCardSchema()],
                'pagination' => self::paginationSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function paginatedVisitsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['items', 'pagination'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => self::visitCardSchema()],
                'pagination' => self::paginationSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function placeStateSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'required' => ['favorite', 'lastVisitedOn'],
                'properties' => [
                    'favorite' => ['type' => 'boolean'],
                    'lastVisitedOn' => ['type' => ['string', 'null'], 'format' => 'date'],
                ],
            ],
        ];
    }
}
