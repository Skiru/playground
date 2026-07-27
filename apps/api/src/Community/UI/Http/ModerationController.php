<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\ClaimModerationCase;
use App\Community\Application\UseCase\GetModerationCase;
use App\Community\Application\UseCase\ListModerationQueue;
use App\Community\Application\UseCase\ModerateContent;
use App\Community\Domain\Moderation\ModerationActionType;
use App\Shared\Application\CorrelationId;
use App\Shared\Application\Exception\ApiException;
use App\Shared\Application\Security\CsrfValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ModerationController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly ListModerationQueue $listQueueUseCase,
        private readonly ModerateContent $moderateContentUseCase,
        private readonly GetModerationCase $getCaseUseCase,
        private readonly ClaimModerationCase $claimCaseUseCase,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
        private readonly ValidatorInterface $validator,
        private readonly CsrfValidator $csrfValidator,
        private readonly RateLimiterFactory $moderatorWrite,
    ) {
    }

    #[Route('/api/v1/moderation/queue', name: 'api_moderation_queue', methods: ['GET'])]
    public function listQueue(Request $request): JsonResponse
    {
        // Require auth
        $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Require role
        if (!$this->security->isGranted('ROLE_MODERATOR') && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new ApiException(403, 'Access denied.', 'MODERATOR_ROLE_REQUIRED');
        }

        $statusFilter = $request->query->get('status');
        if (null !== $statusFilter && !\in_array($statusFilter, ['OPEN', 'IN_REVIEW', 'RESOLVED', 'DISMISSED'], true)) {
            throw new ApiException(400, 'Invalid moderation status filter.', 'VALIDATION_FAILURE');
        }
        $cursor = $request->query->get('cursor');
        $limit = $request->query->get('limit');

        $limitInt = null !== $limit && is_numeric($limit) ? min(100, max(1, (int) $limit)) : 50;

        $result = $this->listQueueUseCase->execute($statusFilter, $cursor, $limitInt);

        $response = new JsonResponse($result);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/api/v1/moderation/case/{reportId}', name: 'api_moderation_case_get', methods: ['GET'])]
    public function getCase(string $reportId): JsonResponse
    {
        $this->getAuthenticatedUser($this->security, $this->userLookup);

        if (!$this->security->isGranted('ROLE_MODERATOR') && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new ApiException(403, 'Access denied.', 'MODERATOR_ROLE_REQUIRED');
        }

        try {
            $reportUuid = Uuid::fromString($reportId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid reportId format.', 'VALIDATION_FAILURE');
        }

        $result = $this->getCaseUseCase->execute($reportUuid);

        $response = new JsonResponse($result);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/api/v1/moderation/case/{reportId}/claim', name: 'api_moderation_case_claim', methods: ['POST'])]
    public function claimCase(Request $request, string $reportId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);
        $this->validateCsrf($request, $this->csrfValidator);

        if (!$this->security->isGranted('ROLE_MODERATOR') && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new ApiException(403, 'Access denied.', 'MODERATOR_ROLE_REQUIRED');
        }

        $this->checkRateLimit($this->moderatorWrite, 'user_'.$user->getId()->toString());

        try {
            $reportUuid = Uuid::fromString($reportId);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid reportId format.', 'VALIDATION_FAILURE');
        }

        $this->claimCaseUseCase->execute($reportUuid, $user->getId());

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/api/v1/moderation/action', name: 'api_moderation_action', methods: ['POST'])]
    public function moderate(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);
        $this->validateCsrf($request, $this->csrfValidator);

        // Require role
        if (!$this->security->isGranted('ROLE_MODERATOR') && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new ApiException(403, 'Access denied.', 'MODERATOR_ROLE_REQUIRED');
        }

        // Rate limit
        $this->checkRateLimit($this->moderatorWrite, 'user_'.$user->getId()->toString());

        $constraints = [
            'reportId' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
            ],
            'action' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Choice(choices: ['HIDE', 'REMOVE', 'RESTORE', 'LOCK', 'UNLOCK', 'PIN', 'UNPIN', 'DISMISS_REPORT', 'RESOLVE_REPORT']),
            ],
            'reason' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(min: 1),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        try {
            $reportUuid = Uuid::fromString((string) $data['reportId']);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid reportId format.', 'VALIDATION_FAILURE');
        }

        $correlationId = $request->attributes->get(CorrelationId::ATTRIBUTE);
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (!\is_string($idempotencyKey) || !Uuid::isValid($idempotencyKey)) {
            throw new ApiException(400, 'Invalid Idempotency-Key header.', 'VALIDATION_FAILURE');
        }

        $this->moderateContentUseCase->execute(
            $user->getId(),
            $reportUuid,
            ModerationActionType::from((string) $data['action']),
            (string) $data['reason'],
            $idempotencyKey,
            \is_string($correlationId) ? $correlationId : null
        );

        return new JsonResponse(['status' => 'success']);
    }
}
