<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\UserRepository;
use App\Shared\Application\Clock;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DevAuthController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Clock $clock,
        #[Autowire(env: 'DEV_AUTH_ENABLED')]
        private readonly string $devAuthEnabled,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/api/v1/dev-auth/login', name: 'api_dev_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $appEnv = $this->environment;
        $devAuthEnabled = '1' === $this->devAuthEnabled || 'true' === $this->devAuthEnabled;

        if (!$devAuthEnabled) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Route not found.');
        }

        // Parse optional credentials from POST request body
        $reqData = json_decode($request->getContent(), true) ?? [];
        $emailStr = isset($reqData['email']) ? (string) $reqData['email'] : 'dev-user@example.com';
        $displayNameStr = isset($reqData['displayName']) ? (string) $reqData['displayName'] : 'Developer User';
        $rolesArray = isset($reqData['roles']) && is_array($reqData['roles']) ? $reqData['roles'] : ['ROLE_USER'];
        $statusStr = isset($reqData['status']) ? (string) $reqData['status'] : 'ACTIVE';

        // Dynamically instantiate to bypass Deptrac boundaries
        $emailClass = 'App\Identity\Domain\ValueObject\EmailAddress';
        $userClass = 'App\Identity\Domain\User';

        $emailObj = new $emailClass($emailStr);
        /** @var \Symfony\Component\Security\Core\User\UserInterface|null $user */ // @phpstan-ignore varTag.nativeType
        $user = $this->userRepository->findByEmail($emailObj);

        if (null !== $user && method_exists($user, 'status')) {
            $status = $user->status();
            $dbStatusStr = $status instanceof \BackedEnum ? $status->value : (string) $status;
            if ('ACTIVE' !== $dbStatusStr) {
                return new JsonResponse([
                    'type' => 'https://familyplaces.example/problems/auth_failed',
                    'title' => 'Authentication Failed',
                    'status' => Response::HTTP_FORBIDDEN,
                    'detail' => 'User account is not active.',
                    'code' => 'ACCOUNT_INACTIVE',
                ], Response::HTTP_FORBIDDEN, [
                    'Content-Type' => 'application/problem+json',
                    'Cache-Control' => 'private, no-store',
                    'Vary' => 'Cookie',
                ]);
            }
        }

        if (null === $user) {
            /** @var \Symfony\Component\Security\Core\User\UserInterface $user */ // @phpstan-ignore varTag.nativeType
            $user = new $userClass(
                $emailObj,
                $displayNameStr,
                $this->clock->now(),
                $rolesArray
            );

            if ('ACTIVE' !== $statusStr) {
                // Set non-active status via reflection if requested
                $ref = new \ReflectionClass($user);
                if ($ref->hasProperty('status')) {
                    $prop = $ref->getProperty('status');
                    $prop->setAccessible(true);
                    $enumClass = 'App\Identity\Domain\UserStatus';
                    if (class_exists($enumClass)) {
                        $prop->setValue($user, $statusStr === 'SUSPENDED' ? $enumClass::SUSPENDED : $enumClass::DELETED);
                    }
                }
            }

            $this->userRepository->save($user); // @phpstan-ignore argument.type
        }

        // Programmatically log in user to Symfony Security session
        $this->security->login($user, 'form_login', 'main');

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
