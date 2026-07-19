<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Review;

use App\Community\Domain\Review\Review;
use App\Community\Domain\Review\ReviewRepository;
use App\Community\Domain\Review\ReviewStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalReviewRepository implements ReviewRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findById(Uuid $id): ?Review
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM reviews WHERE id = :id',
            ['id' => $id->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findActiveByUserAndPlace(Uuid $userId, Uuid $placeId): ?Review
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM reviews WHERE author_id = :author_id AND place_id = :place_id AND status IN (\'PUBLISHED\', \'HIDDEN\') LIMIT 1',
            [
                'author_id' => $userId->toRfc4122(),
                'place_id' => $placeId->toRfc4122(),
            ]
        );

        if (false === $row) {
            return null;
        }

        return $this->reconstitute($row);
    }

    public function findByPlaceId(Uuid $placeId, int $page, int $pageSize, string $sort): array
    {
        $offset = ($page - 1) * $pageSize;
        $order = match ($sort) {
            'highest' => 'rating DESC, created_at DESC',
            'lowest' => 'rating ASC, created_at DESC',
            default => 'created_at DESC', // 'newest'
        };

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM reviews WHERE place_id = :place_id AND status = 'PUBLISHED' ORDER BY {$order} LIMIT :limit OFFSET :offset",
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
            'SELECT COUNT(*) FROM reviews WHERE place_id = :place_id AND status = \'PUBLISHED\'',
            ['place_id' => $placeId->toRfc4122()]
        );
    }

    public function findByAuthorId(Uuid $authorId, int $page, int $pageSize): array
    {
        $offset = ($page - 1) * $pageSize;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM reviews WHERE author_id = :author_id AND status IN (\'PUBLISHED\', \'HIDDEN\') ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            [
                'author_id' => $authorId->toRfc4122(),
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

    public function countByAuthorId(Uuid $authorId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM reviews WHERE author_id = :author_id AND status IN (\'PUBLISHED\', \'HIDDEN\')',
            ['author_id' => $authorId->toRfc4122()]
        );
    }

    public function save(Review $review): void
    {
        $id = $review->id()->toRfc4122();
        $placeId = $review->placeId()->toRfc4122();
        $authorId = $review->authorId()->toRfc4122();
        $rating = $review->rating();
        $body = $review->body();
        $visitedOn = $review->visitedOn()?->format('Y-m-d');
        $status = $review->status()->value;
        $createdAt = $review->createdAt()->format('Y-m-d H:i:s');
        $updatedAt = $review->updatedAt()->format('Y-m-d H:i:s');
        $version = $review->version();

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM reviews WHERE id = :id',
            ['id' => $id]
        );

        if ($exists) {
            $affected = $this->connection->executeStatement(
                'UPDATE reviews SET 
                    rating = :rating,
                    body = :body,
                    visited_on = :visited_on,
                    status = :status,
                    updated_at = :updated_at,
                    version = version + 1
                 WHERE id = :id AND version = :version',
                [
                    'id' => $id,
                    'rating' => $rating,
                    'body' => $body,
                    'visited_on' => $visitedOn,
                    'status' => $status,
                    'updated_at' => $updatedAt,
                    'version' => $version,
                ]
            );

            if (0 === $affected) {
                throw new \RuntimeException('CONCURRENCY_ERROR');
            }

            $ref = new \ReflectionProperty($review, 'version');
            $ref->setValue($review, $version + 1);
        } else {
            $this->connection->executeStatement(
                'INSERT INTO reviews (id, place_id, author_id, rating, body, visited_on, status, created_at, updated_at, version) 
                 VALUES (:id, :place_id, :author_id, :rating, :body, :visited_on, :status, :created_at, :updated_at, :version)',
                [
                    'id' => $id,
                    'place_id' => $placeId,
                    'author_id' => $authorId,
                    'rating' => $rating,
                    'body' => $body,
                    'visited_on' => $visitedOn,
                    'status' => $status,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'version' => $version,
                ]
            );
        }
    }

    private function reconstitute(array $row): Review
    {
        return new Review(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['place_id']),
            Uuid::fromString((string) $row['author_id']),
            (int) $row['rating'],
            (string) $row['body'],
            null === $row['visited_on'] ? null : new \DateTimeImmutable((string) $row['visited_on']),
            ReviewStatus::from((string) $row['status']),
            new \DateTimeImmutable((string) $row['created_at']),
            new \DateTimeImmutable((string) $row['updated_at']),
            (int) $row['version']
        );
    }
}
