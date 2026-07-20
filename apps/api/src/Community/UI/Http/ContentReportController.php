<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use App\Community\Application\UseCase\ReportContent;
use App\Community\Domain\Moderation\ReportReason;
use App\Community\Domain\Moderation\TargetType;
use App\Shared\Application\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ContentReportController
{
    use ControllerHelperTrait;

    public function __construct(
        private readonly ReportContent $reportUseCase,
        private readonly Security $security,
        private readonly ActiveCommunityUserLookup $userLookup,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $reportWrite,
    ) {
    }

    #[Route('/api/v1/content-reports', name: 'api_content_reports', methods: ['POST'])]
    public function reportContent(Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $user = $this->getAuthenticatedUser($this->security, $this->userLookup);

        // Rate limit
        $this->checkRateLimit($this->reportWrite, 'user_'.$user->getId()->toString());

        $constraints = [
            'targetId' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Type('string'),
            ],
            'targetType' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Choice(choices: ['REVIEW', 'PLACE_COMMENT', 'FORUM_THREAD', 'FORUM_POST']),
            ],
            'reason' => [
                new \Symfony\Component\Validator\Constraints\NotBlank(),
                new \Symfony\Component\Validator\Constraints\Choice(choices: ['SPAM', 'HARASSMENT', 'INAPPROPRIATE', 'MISINFORMATION', 'PRIVACY_CONCERN', 'OTHER']),
            ],
            'details' => [
                new \Symfony\Component\Validator\Constraints\Type('string'),
                new \Symfony\Component\Validator\Constraints\Length(max: 1000),
            ],
        ];

        $data = $this->parseAndValidateJson($request, $this->validator, $constraints);

        try {
            $targetUuid = Uuid::fromString((string) $data['targetId']);
        } catch (\InvalidArgumentException) {
            throw new ApiException(400, 'Invalid targetId format.', 'VALIDATION_FAILURE');
        }

        $report = $this->reportUseCase->execute(
            $user->getId(),
            $targetUuid,
            TargetType::from((string) $data['targetType']),
            ReportReason::from((string) $data['reason']),
            isset($data['details']) ? (string) $data['details'] : null
        );

        return new JsonResponse([
            'id' => $report->id()->toString(),
            'targetId' => $report->targetId()->toString(),
            'targetType' => $report->targetType()->value,
            'reason' => $report->reason()->value,
            'details' => $report->details(),
            'status' => $report->status()->value,
            'createdAt' => $report->createdAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }
}
