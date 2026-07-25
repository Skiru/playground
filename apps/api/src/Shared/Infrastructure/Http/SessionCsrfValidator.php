<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Security\CsrfValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SessionCsrfValidator implements CsrfValidator
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function validate(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (null === $token || '' === trim($token)) {
            throw new AccessDeniedHttpException('CSRF token is missing.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('api_session', $token))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
