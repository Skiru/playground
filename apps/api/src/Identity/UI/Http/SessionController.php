<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\UI\Security\CsrfValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SessionController
{
    public function __construct(
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CsrfValidator $csrfValidator,
    ) {
    }

    #[Route('/api/v1/session', name: 'api_session', methods: ['GET'])]
    public function session(): JsonResponse
    {
        $user = $this->security->getUser();

        if (null === $user) {
            $response = new JsonResponse([
                'authenticated' => false,
                'user' => null,
                'csrfToken' => null,
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Vary', 'Cookie');

            return $response;
        }

        if (method_exists($user, 'getDisplayName')) {
            $displayName = $user->getDisplayName();
            $id = method_exists($user, 'getId') ? $user->getId()->toString() : $user->getUserIdentifier();
            $initials = $this->calculateInitials($displayName);
            $roles = $user->getRoles();
        } else {
            $displayName = $user->getUserIdentifier();
            $id = $user->getUserIdentifier();
            $initials = 'U';
            $roles = $user->getRoles();
        }

        $csrfToken = $this->csrfTokenManager->getToken('api_session')->getValue();

        $response = new JsonResponse([
            'authenticated' => true,
            'user' => [
                'id' => $id,
                'displayName' => $displayName,
                'initials' => $initials,
                'roles' => $roles,
            ],
            'csrfToken' => $csrfToken,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    #[Route('/api/v1/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // Require CSRF token
        $this->csrfValidator->validate($request);

        // Perform programmatic logout
        $this->security->logout(false);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    private function calculateInitials(string $displayName): string
    {
        $words = preg_split('/\s+/', trim($displayName));
        $initials = '';
        if ($words) {
            foreach ($words as $word) {
                if ('' !== $word) {
                    $initials .= mb_strtoupper(mb_substr($word, 0, 1));
                }
            }
        }
        $initials = mb_substr($initials, 0, 2);

        return '' === $initials ? 'U' : $initials;
    }
}
