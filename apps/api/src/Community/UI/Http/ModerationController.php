<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\ListModerationQueue;
use App\Community\Application\UseCase\ModerateContent;
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
        $page = $request->query->get('page');
        $pageSize = $request->query->get('pageSize');

        $pageInt = null !== $page && is_numeric($page) ? max(1, (int) $page) : 1;
        $pageSizeInt = null !== $pageSize && is_numeric($pageSize) ? min(50, max(1, (int) $pageSize)) : 20;

        $result = $this->listQueueUseCase->execute($statusFilter, $pageInt, $pageSizeInt);

        return new JsonResponse($result);
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
            'targetId' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
            ],
            'targetType' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Choice(choices: ['REVIEW', 'PLACE_COMMENT', 'FORUM_THREAD', 'FORUM_POST']),
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
            $targetUuid = Uuid::fromString((string) $data['targetId']);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid targetId format.', 'VALIDATION_FAILURE');
        }

        $this->moderateContentUseCase->execute(
            $user->getId(),
            $targetUuid,
            TargetType::from((string) $data['targetType']),
            ModerationActionType::from((string) $data['action']),
            (string) $data['reason']
        );

        return new JsonResponse(['status' => 'success']);
    }
}
