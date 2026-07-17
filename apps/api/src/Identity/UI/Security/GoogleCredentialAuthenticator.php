<?php

declare(strict_types=1);

namespace App\Identity\UI\Security;

use App\Identity\Application\AuthenticateWithGoogle;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class GoogleCredentialAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly AuthenticateWithGoogle $authenticateWithGoogle,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.google_login')]
        private readonly RateLimiterFactory $googleLoginLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return '/api/v1/auth/google' === $request->getPathInfo() && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        // 1. Check rate limit using client IP
        $limiter = $this->googleLoginLimiter->create($request->getClientIp() ?? '127.0.0.1');
        if (!$limiter->consume(1)->isAccepted()) {
            throw new BadCredentialsException('AUTH_RATE_LIMITED');
        }

        // 2. Validate Origin / Host for same-origin
        $origin = $request->headers->get('Origin');
        $host = $request->headers->get('Host');
        if (null !== $origin && '' !== $origin) {
            $originHost = parse_url($origin, \PHP_URL_HOST);
            if (\is_string($originHost) && \is_string($host) && !str_contains($host, $originHost) && !str_contains($originHost, $host)) {
                throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID');
            }
        }

        // 3. Request verification (content type, size, format)
        if ('application/json' !== $request->getContentTypeFormat()) {
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID');
        }

        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID');
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID');
        }

        $credential = $data['credential'] ?? null;
        if (null === $credential || '' === trim($credential)) {
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID');
        }

        if (\strlen($credential) > 4096) {
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID');
        }

        try {
            $user = $this->authenticateWithGoogle->authenticate($credential);
        } catch (\App\Identity\Application\Exception\AccountLinkRequiredException $e) {
            throw new BadCredentialsException('ACCOUNT_LINK_REQUIRED', 0, $e);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'active')) {
                throw new BadCredentialsException('ACCOUNT_INACTIVE', 0, $e);
            }
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID', 0, $e);
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'empty')) {
                throw new BadCredentialsException('GOOGLE_CONFIGURATION_INVALID', 0, $e);
            }
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID', 0, $e);
        } catch (\Throwable $e) {
            throw new BadCredentialsException('GOOGLE_CREDENTIAL_INVALID', 0, $e);
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        if (!$user instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            throw new \LogicException('Expected instance of UserInterface.');
        }

        // Session ID hardening - session migration on login success
        if ($request->hasSession()) {
            $request->getSession()->migrate(true);
        }

        $displayName = method_exists($user, 'getDisplayName') ? $user->getDisplayName() : $user->getUserIdentifier();
        $id = method_exists($user, 'getId') ? $user->getId()->toString() : $user->getUserIdentifier();
        $initials = $this->calculateInitials($displayName);
        $csrfToken = $this->csrfTokenManager->getToken('api_session')->getValue();

        $response = new JsonResponse([
            'authenticated' => true,
            'user' => [
                'id' => $id,
                'displayName' => $displayName,
                'initials' => $initials,
                'roles' => $user->getRoles(),
            ],
            'csrfToken' => $csrfToken,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $previous = $exception->getPrevious() ?? $exception;
        $message = $exception->getMessage();
        $correlationId = $request->attributes->get(\App\Shared\Application\CorrelationId::ATTRIBUTE);

        $code = 'GOOGLE_CREDENTIAL_INVALID';
        $status = Response::HTTP_UNAUTHORIZED;
        $detail = 'Invalid Google credential.';

        if ('AUTH_RATE_LIMITED' === $message) {
            $code = 'AUTH_RATE_LIMITED';
            $status = Response::HTTP_TOO_MANY_REQUESTS;
            $detail = 'Too many login attempts. Please try again later.';
        } elseif ('ACCOUNT_LINK_REQUIRED' === $message) {
            $code = 'ACCOUNT_LINK_REQUIRED';
            $status = Response::HTTP_CONFLICT;
            $detail = 'An account with this email address already exists. Manual linking is required.';
        } elseif ('ACCOUNT_INACTIVE' === $message) {
            $code = 'ACCOUNT_INACTIVE';
            $status = Response::HTTP_FORBIDDEN;
            $detail = 'User account is not active.';
        } elseif ('GOOGLE_CONFIGURATION_INVALID' === $message) {
            $code = 'GOOGLE_CONFIGURATION_INVALID';
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $detail = 'Google integration is misconfigured.';
        }

        // Log internally without sensitive data like full credentials, claims or session ID
        $this->logger->error('Google login failure', [
            'correlationId' => $correlationId,
            'exception_class' => $previous::class,
            'message' => $previous->getMessage(),
        ]);

        $headers = [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store',
        ];
        if ('AUTH_RATE_LIMITED' === $code) {
            $headers['Retry-After'] = '60';
        }

        return new JsonResponse([
            'type' => 'https://familyplaces.example/problems/auth_failed',
            'title' => 'Authentication Failed',
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'correlationId' => $correlationId,
        ], $status, $headers);
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
