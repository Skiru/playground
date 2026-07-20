<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Moderation\TargetType;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\Review\ReviewRepository;
use Symfony\Component\Uid\Uuid;

final class ListModerationQueue
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
        private readonly ReviewRepository $reviewRepository,
        private readonly PlaceCommentRepository $commentRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $statusFilter, int $page, int $pageSize): array
    {
        $offset = ($page - 1) * $pageSize;
        $params = [
            'limit' => $pageSize,
            'offset' => $offset,
        ];

        $where = [];
        if (null !== $statusFilter && '' !== trim($statusFilter)) {
            $where[] = 'status = :status';
            $params['status'] = $statusFilter;
        }

        $whereSql = !empty($where) ? 'WHERE '.implode(' AND ', $where) : '';

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM content_reports {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            $params,
            [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]
        );

        $totalItems = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM content_reports {$whereSql}",
            $params
        );

        $totalPages = (int) ceil($totalItems / $pageSize);

        // Batch load reporter profiles
        $reporterIds = array_map(static fn ($r) => Uuid::fromString((string) $r['reporter_id']), $rows);
        $profiles = $this->authorProfileLookup->getProfiles($reporterIds);

        $items = [];
        foreach ($rows as $row) {
            $reporterIdStr = (string) $row['reporter_id'];
            $targetId = Uuid::fromString((string) $row['target_id']);
            $targetType = TargetType::from((string) $row['target_type']);

            // Get original evidence content
            $originalContent = '[Przedmiot nie istnieje lub usunięty]';
            $authorIdStr = null;
            $authorProfile = null;

            switch ($targetType) {
                case TargetType::REVIEW:
                    $item = $this->reviewRepository->findById($targetId);
                    if (null !== $item) {
                        $originalContent = $item->body();
                        $authorIdStr = $item->authorId()->toString();
                    }
                    break;
                case TargetType::PLACE_COMMENT:
                    $item = $this->commentRepository->findById($targetId);
                    if (null !== $item) {
                        $originalContent = $item->body();
                        $authorIdStr = $item->authorId()->toString();
                    }
                    break;
                case TargetType::FORUM_THREAD:
                    $item = $this->threadRepository->findById($targetId);
                    if (null !== $item) {
                        $originalContent = $item->title();
                        $authorIdStr = $item->authorId()->toString();
                    }
                    break;
                case TargetType::FORUM_POST:
                    $item = $this->postRepository->findById($targetId);
                    if (null !== $item) {
                        $originalContent = $item->body();
                        $authorIdStr = $item->authorId()->toString();
                    }
                    break;
            }

            if (null !== $authorIdStr) {
                $authorProfile = $this->authorProfileLookup->getProfile(Uuid::fromString($authorIdStr));
            }

            $items[] = [
                'id' => (string) $row['id'],
                'reporterId' => $reporterIdStr,
                'reporter' => $profiles[$reporterIdStr] ?? [
                    'id' => $reporterIdStr,
                    'displayName' => 'Anonimowy reporter',
                    'initials' => 'A',
                ],
                'targetType' => $targetType->value,
                'targetId' => $targetId->toString(),
                'reason' => (string) $row['reason'],
                'details' => null !== $row['details'] ? (string) $row['details'] : null,
                'status' => (string) $row['status'],
                'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(\DateTimeInterface::ATOM),
                'resolvedAt' => null !== $row['resolved_at'] ? (new \DateTimeImmutable((string) $row['resolved_at']))->format(\DateTimeInterface::ATOM) : null,
                'resolvedBy' => null !== $row['resolved_by'] ? (string) $row['resolved_by'] : null,
                'evidence' => $originalContent,
                'author' => $authorProfile,
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => max(1, $totalPages),
            ],
        ];
    }
}
