<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Application\Port\PublishedPlaceLookup;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

final class ListComments
{
    public function __construct(
        private readonly PublishedPlaceLookup $publishedPlaceLookup,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
        private readonly \Doctrine\DBAL\Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Uuid $placeId, int $limit, ?string $cursorStr): array
    {
        if (!$this->publishedPlaceLookup->isPublished($placeId)) {
            throw new ApiException(404, 'Place not found or not published.', 'MISSING_PUBLIC_RESOURCE');
        }

        // Get total roots count for metadata
        $rootCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM place_comments WHERE place_id = :place_id AND parent_id IS NULL AND status IN (\'PUBLISHED\', \'DELETED_BY_AUTHOR\')',
            ['place_id' => $placeId->toRfc4122()]
        );

        // Get total replies count for metadata
        $replyCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM place_comments WHERE place_id = :place_id AND parent_id IS NOT NULL AND status IN (\'PUBLISHED\', \'DELETED_BY_AUTHOR\')',
            ['place_id' => $placeId->toRfc4122()]
        );

        // Decode cursor
        $cursor = $this->decodeCursor($cursorStr);

        // Fetch roots using cursor
        // Stable order: created_at ASC, id ASC
        $params = [
            'place_id' => $placeId->toRfc4122(),
            'limit' => $limit + 1,
        ];

        $sql = 'SELECT * FROM place_comments 
                WHERE place_id = :place_id AND parent_id IS NULL AND status IN (\'PUBLISHED\', \'DELETED_BY_AUTHOR\')';

        if (null !== $cursor) {
            $sql .= ' AND (created_at > :cursor_created_at OR (created_at = :cursor_created_at AND id > :cursor_id))';
            $params['cursor_created_at'] = $cursor['createdAt'];
            $params['cursor_id'] = $cursor['id'];
        }

        $sql .= ' ORDER BY created_at ASC, id ASC LIMIT :limit';

        $rows = $this->connection->fetchAllAssociative($sql, $params, [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        $hasNextPage = \count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        $rootComments = array_map(fn ($r) => $this->reconstituteComment($r), $rows);

        // Fetch replies for these roots
        $replies = [];
        if (!empty($rootComments)) {
            $rootIds = array_map(static fn ($r) => $r->id()->toRfc4122(), $rootComments);
            $replyRows = $this->connection->fetchAllAssociative(
                'SELECT * FROM place_comments WHERE parent_id IN (:root_ids) AND status IN (\'PUBLISHED\', \'DELETED_BY_AUTHOR\') ORDER BY created_at ASC, id ASC',
                ['root_ids' => $rootIds],
                ['root_ids' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $replies = array_map(fn ($r) => $this->reconstituteComment($r), $replyRows);
        }

        // Group replies by parent_id
        $repliesByParent = [];
        foreach ($replies as $reply) {
            $parentIdStr = $reply->parentId()?->toString();
            if (null !== $parentIdStr) {
                $repliesByParent[$parentIdStr][] = $reply;
            }
        }

        // Collect all author IDs to batch lookup
        $allComments = array_merge($rootComments, $replies);
        $authorIds = array_map(static fn ($c) => $c->authorId(), $allComments);
        $profiles = $this->authorProfileLookup->getProfiles($authorIds);

        // Map and project to response structure
        $items = [];
        foreach ($rootComments as $root) {
            $rootIdStr = $root->id()->toString();
            $rootProjected = $this->projectComment($root, $profiles);

            // Map replies
            $rootReplies = $repliesByParent[$rootIdStr] ?? [];
            $rootProjected['replies'] = array_map(fn ($reply) => $this->projectComment($reply, $profiles), $rootReplies);

            $items[] = $rootProjected;
        }

        // Next cursor calculation
        $nextCursor = null;
        if (!empty($rootComments)) {
            $lastRoot = end($rootComments);
            $nextCursor = $this->encodeCursor($lastRoot->createdAt()->format('Y-m-d H:i:s'), $lastRoot->id()->toString());
        }

        return [
            'items' => $items,
            'pagination' => [
                'rootCount' => $rootCount,
                'replyCount' => $replyCount,
                'nextCursor' => $nextCursor,
                'hasNextPage' => $hasNextPage,
            ],
        ];
    }

    /**
     * @param array<string, array{id: string, displayName: string, initials: string}> $profiles
     *
     * @return array<string, mixed>
     */
    private function projectComment(\App\Community\Domain\PlaceDiscussion\PlaceComment $comment, array $profiles): array
    {
        $authorIdStr = $comment->authorId()->toString();

        if (PlaceCommentStatus::DELETED_BY_AUTHOR === $comment->status()) {
            $author = [
                'id' => $authorIdStr,
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];
            $body = 'Treść usunięta przez autora';
        } else {
            $author = $profiles[$authorIdStr] ?? [
                'id' => $authorIdStr,
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];
            $body = $comment->body();
        }

        return [
            'id' => $comment->id()->toString(),
            'placeId' => $comment->placeId()->toString(),
            'authorId' => $authorIdStr,
            'author' => $author,
            'parentId' => $comment->parentId()?->toString(),
            'body' => $body,
            'status' => $comment->status()->value,
            'createdAt' => $comment->createdAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->updatedAt()->format(\DateTimeInterface::ATOM),
            'version' => $comment->version(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reconstituteComment(array $row): \App\Community\Domain\PlaceDiscussion\PlaceComment
    {
        return new \App\Community\Domain\PlaceDiscussion\PlaceComment(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['place_id']),
            Uuid::fromString((string) $row['author_id']),
            null === $row['parent_id'] ? null : Uuid::fromString((string) $row['parent_id']),
            (string) $row['body'],
            PlaceCommentStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['created_at']),
            new \DateTimeImmutable((string) $row['updated_at']),
            (int) $row['version']
        );
    }

    private function encodeCursor(?string $createdAt, ?string $id): ?string
    {
        if (null === $createdAt || null === $id) {
            return null;
        }

        return base64_encode((string) json_encode(['createdAt' => $createdAt, 'id' => $id]));
    }

    /**
     * @return array{createdAt: string, id: string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }
        $decoded = json_decode((string) base64_decode($cursor), true);
        if (!\is_array($decoded) || !isset($decoded['createdAt']) || !isset($decoded['id'])) {
            return null;
        }

        return $decoded;
    }
}
