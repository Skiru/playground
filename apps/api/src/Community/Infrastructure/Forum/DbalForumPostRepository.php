<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Forum;

use App\Community\Domain\Forum\ForumPost;
use App\Community\Domain\Forum\ForumPostRepository;
use App\Community\Domain\Forum\ForumPostStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalForumPostRepository implements ForumPostRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(Uuid $id): ?ForumPost
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM forum_posts WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findByThreadId(Uuid $threadId, ?string $cursorId, ?\DateTimeImmutable $cursorCreatedAt, int $limit): array
    {
        $sql = 'SELECT * FROM forum_posts 
                WHERE thread_id = :thread_id AND status IN (\'PUBLISHED\', \'DELETED_BY_AUTHOR\')';

        $params = [
            'thread_id' => $threadId->toRfc4122(),
            'limit' => $limit,
        ];

        if (null !== $cursorId && null !== $cursorCreatedAt) {
            $sql .= ' AND (created_at > :cursor_created_at OR (created_at = :cursor_created_at AND id > :cursor_id))';
            $params['cursor_created_at'] = $cursorCreatedAt->format('Y-m-d H:i:s');
            $params['cursor_id'] = $cursorId;
        }

        $sql .= ' ORDER BY created_at ASC, id ASC LIMIT :limit';

        $rows = $this->connection->fetchAllAssociative($sql, $params, [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map([$this, 'reconstitute'], $rows);
    }

    public function save(ForumPost $post): void
    {
        $id = $post->id()->toRfc4122();
        $threadId = $post->threadId()->toRfc4122();
        $authorId = $post->authorId()->toRfc4122();
        $parentId = $post->parentId()?->toRfc4122();
        $body = $post->body();
        $status = $post->status()->value;
        $createdAt = $post->createdAt()->format('Y-m-d H:i:s');
        $updatedAt = $post->updatedAt()->format('Y-m-d H:i:s');
        $version = $post->version();

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM forum_posts WHERE id = :id',
            ['id' => $id]
        );

        if ($exists) {
            $affected = $this->connection->executeStatement(
                'UPDATE forum_posts SET 
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

            $post->advanceVersion();
        } else {
            $this->connection->executeStatement(
                'INSERT INTO forum_posts (id, thread_id, author_id, parent_id, body, status, created_at, updated_at, version) 
                 VALUES (:id, :thread_id, :author_id, :parent_id, :body, :status, :created_at, :updated_at, :version)',
                [
                    'id' => $id,
                    'thread_id' => $threadId,
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

    /**
     * @param array<string, mixed> $row
     */
    private function reconstitute(array $row): ForumPost
    {
        return new ForumPost(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['thread_id']),
            Uuid::fromString((string) $row['author_id']),
            null === $row['parent_id'] ? null : Uuid::fromString((string) $row['parent_id']),
            (string) $row['body'],
            ForumPostStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['created_at']),
            new \DateTimeImmutable((string) $row['updated_at']),
            (int) $row['version']
        );
    }
}
