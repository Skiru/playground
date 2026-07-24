<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\ClaimModerationCase;
use App\Community\Application\UseCase\GetModerationCase;
use App\Community\Application\UseCase\ListModerationQueue;
use App\Community\Application\UseCase\ModerateContent;
use App\Community\Domain\Moderation\ContentReportRepository;
use App\Community\Domain\Moderation\ModerationActionType;
use App\Community\Domain\Moderation\TargetType;
use App\Shared\Application\Exception\ApiException;
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
        private readonly ContentReportRepository $reportRepository,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
        private readonly ValidatorInterface $validator,
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
        $cursor = $request->query->get('cursor');
        $limit = $request->query->get('limit');

        $limitInt = null !== $limit && is_numeric($limit) ? min(100, max(1, (int) $limit)) : 50;

        $result = $this->listQueueUseCase->execute($statusFilter, $cursor, $limitInt);

        return new JsonResponse($result);
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

        return new JsonResponse($result);
    }

    #[Route('/api/v1/moderation/case/{reportId}/claim', name: 'api_moderation_case_claim', methods: ['POST'])]
    public function claimCase(Request $request, string $reportId): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

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
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Require role
        if (!$this->security->isGranted('ROLE_MODERATOR') && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new ApiException(403, 'Access denied.', 'MODERATOR_ROLE_REQUIRED');
        }

        // Rate limit
        $this->checkRateLimit($this->moderatorWrite, 'user_'.$user->getId()->toString());

        $constraints = [
            'reportId' => [
                new \Symfony\Component\Validator\Constraints\Optional([
                    new \Symfony\Component\Validator\Constraints\NotBlank(),
                    new \Symfony\Component\Validator\Constraints\Type('string'),
                ]),
            ],
            'targetId' => [
                new \Symfony\Component\Validator\Constraints\Optional([
                    new \Symfony\Component\Validator\Constraints\NotBlank(),
                    new \Symfony\Component\Validator\Constraints\Type('string'),
                ]),
            ],
            'targetType' => [
                new \Symfony\Component\Validator\Constraints\Optional([
                    new \Symfony\Component\Validator\Constraints\NotBlank(),
                    new \Symfony\Component\Validator\Constraints\Choice(choices: ['REVIEW', 'PLACE_COMMENT', 'FORUM_THREAD', 'FORUM_POST']),
                ]),
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

        if (!isset($data['reportId']) && (!isset($data['targetId']) || !isset($data['targetType']))) {
            throw new ApiException(400, 'Either reportId or targetId and targetType must be provided.', 'VALIDATION_FAILURE');
        }

        if (isset($data['reportId'])) {
            try {
                $reportUuid = Uuid::fromString((string) $data['reportId']);
            } catch (\InvalidArgumentException) {
                throw new ApiException(400, 'Invalid reportId format.', 'VALIDATION_FAILURE');
            }
        } else {
            try {
                $targetUuid = Uuid::fromString((string) $data['targetId']);
            } catch (\InvalidArgumentException) {
                throw new ApiException(400, 'Invalid targetId format.', 'VALIDATION_FAILURE');
            }
            $targetType = TargetType::from((string) $data['targetType']);

            $openReports = $this->reportRepository->findOpenReportsForTarget($targetUuid, $targetType);
            if (empty($openReports)) {
                throw new ApiException(404, 'No open report found for target.', 'MISSING_PUBLIC_RESOURCE');
            }
            $reportUuid = $openReports[0]->id();
        }

        $correlationId = isset($data['correlationId']) ? (string) $data['correlationId'] : null;

        $this->moderateContentUseCase->execute(
            $user->getId(),
            $reportUuid,
            ModerationActionType::from((string) $data['action']),
            (string) $data['reason'],
            $correlationId
        );

        return new JsonResponse(['status' => 'success']);
    }
}
