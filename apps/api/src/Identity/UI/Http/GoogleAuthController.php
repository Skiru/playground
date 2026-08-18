<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleAuthController
{
    #[Route('/api/v1/auth/google', name: 'api_auth_google', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        throw new \LogicException('This endpoint is intercepted by GoogleCredentialAuthenticator.');
    }
}
