<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\PlaceDiscussion;

use App\Community\Domain\PlaceDiscussion\PlaceComment;
use App\Community\Domain\PlaceDiscussion\PlaceCommentRepository;
use App\Community\Domain\PlaceDiscussion\PlaceCommentStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPlaceCommentRepository implements PlaceCommentRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findById(Uuid $id): ?PlaceComment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM place_comments WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findByPlaceId(Uuid $placeId, int $page, int $pageSize): array
    {
        $offset = ($page - 1) * $pageSize;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM place_comments 
             WHERE place_id = :place_id AND status IN (\'PUBLISHED\', \'HIDDEN\', \'DELETED_BY_AUTHOR\') 
             ORDER BY created_at ASC LIMIT :limit OFFSET :offset',
            [
                'place_id' => $placeId->toRfc4122(),
                'limit' => $pageSize,
                'offset' => $offset,
            ],
            [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]
        );

        return array_map([$this, 'reconstitute'], $rows);
    }

    public function countByPlaceId(Uuid $placeId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM place_comments WHERE place_id = :place_id AND status IN (\'PUBLISHED\', \'HIDDEN\', \'DELETED_BY_AUTHOR\')',
            ['place_id' => $placeId->toRfc4122()]
        );
    }

    public function save(PlaceComment $comment): void
    {
        $id = $comment->id()->toRfc4122();
        $placeId = $comment->placeId()->toRfc4122();
        $authorId = $comment->authorId()->toRfc4122();
        $parentId = $comment->parentId()?->toRfc4122();
        $body = $comment->body();
        $status = $comment->status()->value;
        $createdAt = $comment->createdAt()->format('Y-m-d H:i:s');
        $updatedAt = $comment->updatedAt()->format('Y-m-d H:i:s');
        $version = $comment->version();

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM place_comments WHERE id = :id',
            ['id' => $id]
        );

        if ($exists) {
            $affected = $this->connection->executeStatement(
                'UPDATE place_comments SET 
                    body = :body,
                    status = :status,
                    updated_at = :updated_at,
                    version = version + 1
                 WHERE id = :id AND version = :version',
                [
                    'id' => $id,
                    'body' => $body,
                    'status' => $status,
                    'updated_at' => $updatedAt,
                    'version' => $version,
                ]
            );

            if (0 === $affected) {
                throw new \RuntimeException('CONCURRENCY_ERROR');
            }

            $ref = new \ReflectionProperty($comment, 'version');
            $ref->setValue($comment, $version + 1);
        } else {
            $this->connection->executeStatement(
                'INSERT INTO place_comments (id, place_id, author_id, parent_id, body, status, created_at, updated_at, version) 
                 VALUES (:id, :place_id, :author_id, :parent_id, :body, :status, :created_at, :updated_at, :version)',
                [
                    'id' => $id,
                    'place_id' => $placeId,
                    'author_id' => $authorId,
                    'parent_id' => $parentId,
                    'body' => $body,
                    'status' => $status,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'version' => $version,
                ]
            );
        }
    }

    private function reconstitute(array $row): PlaceComment
    {
        return new PlaceComment(
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
}
