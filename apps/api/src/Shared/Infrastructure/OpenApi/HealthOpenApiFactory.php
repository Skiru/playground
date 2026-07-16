<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
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

        return $openApi;
    }
}
