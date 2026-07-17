<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/v1/health/live', name: 'api_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/api/v1/health/ready', name: 'api_health_ready', methods: ['GET'])]
    public function ready(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Service Unavailable',
                'status' => 503,
                'detail' => 'The database dependency is unavailable.',
            ], 503, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
