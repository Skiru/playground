<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Forum;

use App\Community\Domain\Forum\ForumThread;
use App\Community\Domain\Forum\ForumThreadRepository;
use App\Community\Domain\Forum\ForumThreadStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalForumThreadRepository implements ForumThreadRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(Uuid $id): ?ForumThread
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM forum_threads WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findByCategoryId(Uuid $categoryId, ?string $cursorId, ?\DateTimeImmutable $cursorPinnedAt, ?\DateTimeImmutable $cursorLastActivityAt, int $limit): array
    {
        $sql = 'SELECT * FROM forum_threads 
                WHERE category_id = :category_id AND status IN (\'PUBLISHED\', \'DELETED_BY_AUTHOR\')';

        $params = [
            'category_id' => $categoryId->toRfc4122(),
            'limit' => $limit,
        ];

        if (null !== $cursorId && null !== $cursorLastActivityAt) {
            $sql .= ' AND (';
            if (null !== $cursorPinnedAt) {
                $sql .= '(pinned_at IS NOT NULL AND (pinned_at < :cursor_pinned_at OR (pinned_at = :cursor_pinned_at AND last_activity_at < :cursor_last_activity) OR (pinned_at = :cursor_pinned_at AND last_activity_at = :cursor_last_activity AND id < :cursor_id)))';
                $sql .= ' OR (pinned_at IS NULL)';
                $params['cursor_pinned_at'] = $cursorPinnedAt->format('Y-m-d H:i:s');
                $params['cursor_last_activity'] = $cursorLastActivityAt->format('Y-m-d H:i:s');
                $params['cursor_id'] = $cursorId;
            } else {
                $sql .= '(pinned_at IS NULL AND (last_activity_at < :cursor_last_activity OR (last_activity_at = :cursor_last_activity AND id < :cursor_id)))';
                $params['cursor_last_activity'] = $cursorLastActivityAt->format('Y-m-d H:i:s');
                $params['cursor_id'] = $cursorId;
            }
            $sql .= ')';
        }

        $sql .= ' ORDER BY (pinned_at IS NOT NULL) DESC, pinned_at DESC, last_activity_at DESC, id DESC LIMIT :limit';

        $rows = $this->connection->fetchAllAssociative($sql, $params, [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map([$this, 'reconstitute'], $rows);
    }

    public function save(ForumThread $thread): void
    {
        $id = $thread->id()->toRfc4122();
        $categoryId = $thread->categoryId()->toRfc4122();
        $authorId = $thread->authorId()->toRfc4122();
        $title = $thread->title();
        $status = $thread->status()->value;
        $createdAt = $thread->createdAt()->format('Y-m-d H:i:s');
        $updatedAt = $thread->updatedAt()->format('Y-m-d H:i:s');
        $lastActivityAt = $thread->lastActivityAt()->format('Y-m-d H:i:s');
        $lockedAt = $thread->lockedAt()?->format('Y-m-d H:i:s');
        $pinnedAt = $thread->pinnedAt()?->format('Y-m-d H:i:s');
        $version = $thread->version();

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM forum_threads WHERE id = :id',
            ['id' => $id]
        );

        if ($exists) {
            $affected = $this->connection->executeStatement(
                'UPDATE forum_threads SET 
                    category_id = :category_id,
                    title = :title,
                    status = :status,
                    updated_at = :updated_at,
                    last_activity_at = :last_activity_at,
                    locked_at = :locked_at,
                    pinned_at = :pinned_at,
                    version = version + 1
                 WHERE id = :id AND version = :version',
                [
                    'id' => $id,
                    'category_id' => $categoryId,
                    'title' => $title,
                    'status' => $status,
                    'updated_at' => $updatedAt,
                    'last_activity_at' => $lastActivityAt,
                    'locked_at' => $lockedAt,
                    'pinned_at' => $pinnedAt,
                    'version' => $version,
                ]
            );

            if (0 === $affected) {
                throw new \RuntimeException('CONCURRENCY_ERROR');
            }

            $thread->advanceVersion();
        } else {
            $this->connection->executeStatement(
                'INSERT INTO forum_threads (id, category_id, author_id, title, status, created_at, updated_at, last_activity_at, locked_at, pinned_at, version) 
                 VALUES (:id, :category_id, :author_id, :title, :status, :created_at, :updated_at, :last_activity_at, :locked_at, :pinned_at, :version)',
                [
                    'id' => $id,
                    'category_id' => $categoryId,
                    'author_id' => $authorId,
                    'title' => $title,
                    'status' => $status,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'last_activity_at' => $lastActivityAt,
                    'locked_at' => $lockedAt,
                    'pinned_at' => $pinnedAt,
                    'version' => $version,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reconstitute(array $row): ForumThread
    {
        return new ForumThread(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['category_id']),
            Uuid::fromString((string) $row['author_id']),
            (string) $row['title'],
            ForumThreadStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['created_at']),
            new \DateTimeImmutable((string) $row['updated_at']),
            new \DateTimeImmutable((string) $row['last_activity_at']),
            null === $row['locked_at'] ? null : new \DateTimeImmutable((string) $row['locked_at']),
            null === $row['pinned_at'] ? null : new \DateTimeImmutable((string) $row['pinned_at']),
            (int) $row['version']
        );
    }
}
