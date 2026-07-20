<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Identity\Domain\User;
use App\Shared\Application\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

trait ControllerHelperTrait
{
    private function getAuthenticatedUser(Security $security, \App\Community\Application\Port\ActiveCommunityUserLookup $userLookup): User
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw new ApiException(401, 'Authentication required.', 'AUTHENTICATION_REQUIRED');
        }

        if (!$userLookup->isActiveUser($user->getId())) {
            throw new ApiException(403, 'User account is not active.', 'INACTIVE_ACCOUNT');
        }

        return $user;
    }

    private function validateCsrf(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (null === $token || '' === trim($token)) {
            throw new ApiException(403, 'CSRF token is missing.', 'CSRF_TOKEN_MISSING');
        }
    }

    private function checkRateLimit(\Symfony\Component\RateLimiter\RateLimiterFactory $limiterFactory, string $key): void
    {
        $limiter = $limiterFactory->create($key);
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            throw new ApiException(429, 'Rate limit exceeded.', 'RATE_LIMIT_EXCEEDED', 'Too many requests. Please try again later.', ['Retry-After' => (string) max(1, $retryAfter)]);
        }
    }

    /**
     * @param array<string, mixed> $constraints
     *
     * @return array<string, mixed>
     */
    private function parseAndValidateJson(Request $request, ValidatorInterface $validator, array $constraints): array
    {
        $content = $request->getContent();
        if (\strlen($content) > 8192) {
            throw new ApiException(400, 'Payload too large.', 'VALIDATION_FAILURE');
        }

        $data = json_decode($content, true);
        if (\JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
            throw new ApiException(400, 'Invalid JSON payload.', 'VALIDATION_FAILURE');
        }

        // Verify extra fields
        $allowedFields = array_keys($constraints);
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new ApiException(400, \sprintf('Extra field "%s" is not allowed.', $key), 'VALIDATION_FAILURE');
            }
        }

        // Validate individual fields using Symfony Validator
        $collectionConstraint = new \Symfony\Component\Validator\Constraints\Collection(
            fields: $constraints,
            allowExtraFields: false,
            allowMissingFields: true
        );
        $violations = $validator->validate($data, $collectionConstraint);

        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getPropertyPath().': '.$violation->getMessage();
            }
            throw new ApiException(400, 'Validation failed: '.implode(', ', $errors), 'VALIDATION_FAILURE');
        }

        return $data;
    }
}
