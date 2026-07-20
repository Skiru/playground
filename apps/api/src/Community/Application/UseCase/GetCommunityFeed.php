<?php

declare(strict_types=1);

namespace App\Community\Application\UseCase;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use Symfony\Component\Uid\Uuid;

final class GetCommunityFeed
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
        private readonly PublicAuthorProfileLookup $authorProfileLookup,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{nextCursor: ?string, hasNextPage: bool}}
     */
    public function execute(int $limit, ?string $cursorStr, ?string $typeFilter, ?string $cityIdFilter, ?string $categoryIdFilter): array
    {
        // Cursor structure: base64({"activityAt": "Y-m-d H:i:s", "id": "uuid"})
        $cursor = null;
        if (null !== $cursorStr && '' !== $cursorStr) {
            $decoded = json_decode((string) base64_decode($cursorStr), true);
            if (\is_array($decoded) && isset($decoded['activityAt'], $decoded['id'])) {
                $cursor = $decoded;
            }
        }

        // Build individual UNION queries
        $subqueries = [];
        $params = [];

        // 1. Forum Threads
        if (null === $typeFilter || 'forum_thread' === $typeFilter) {
            $where = ['t.status = \'PUBLISHED\''];
            $joins = '';
            if (null !== $cityIdFilter) {
                $joins .= ' JOIN forum_categories c ON t.category_id = c.id';
                $where[] = 'c.city_id = :city_id';
                $params['city_id'] = $cityIdFilter;
            }
            if (null !== $categoryIdFilter) {
                $where[] = 't.category_id = :category_id';
                $params['category_id'] = $categoryIdFilter;
            }

            $whereSql = implode(' AND ', $where);
            $subqueries[] = "SELECT 
                'forum_thread' as type,
                t.id as id,
                t.created_at as activity_at,
                t.author_id as author_id,
                t.title as title,
                NULL as body,
                t.category_id as source_id,
                NULL as parent_source_id
            FROM forum_threads t {$joins} WHERE {$whereSql}";
        }

        // 2. Forum Posts
        if (null === $typeFilter || 'forum_post' === $typeFilter) {
            $where = ['p.status = \'PUBLISHED\''];
            $joins = ' JOIN forum_threads t ON p.thread_id = t.id';
            if (null !== $cityIdFilter) {
                $joins .= ' JOIN forum_categories c ON t.category_id = c.id';
                $where[] = 'c.city_id = :city_id';
                $params['city_id'] = $cityIdFilter;
            }
            if (null !== $categoryIdFilter) {
                $where[] = 't.category_id = :category_id';
                $params['category_id'] = $categoryIdFilter;
            }

            $whereSql = implode(' AND ', $where);
            $subqueries[] = "SELECT 
                'forum_post' as type,
                p.id as id,
                p.created_at as activity_at,
                p.author_id as author_id,
                NULL as title,
                p.body as body,
                p.thread_id as source_id,
                t.category_id as parent_source_id
            FROM forum_posts p {$joins} WHERE {$whereSql}";
        }

        // 3. Reviews
        if (null === $typeFilter || 'review' === $typeFilter) {
            $where = ['r.status = \'PUBLISHED\''];
            $joins = '';
            if (null !== $cityIdFilter) {
                $joins .= ' JOIN places pl ON r.place_id = pl.id';
                $where[] = 'pl.city_id = :city_id';
                $params['city_id'] = $cityIdFilter;
            }
            // Reviews do not have forum category filter
            if (null === $categoryIdFilter) {
                $whereSql = implode(' AND ', $where);
                $subqueries[] = "SELECT 
                    'review' as type,
                    r.id as id,
                    r.created_at as activity_at,
                    r.author_id as author_id,
                    NULL as title,
                    r.body as body,
                    r.place_id as source_id,
                    NULL as parent_source_id
                FROM reviews r {$joins} WHERE {$whereSql}";
            }
        }

        // 4. Place Comments (Discussions)
        if (null === $typeFilter || 'place_comment' === $typeFilter) {
            $where = ['pc.status = \'PUBLISHED\''];
            $joins = '';
            if (null !== $cityIdFilter) {
                $joins .= ' JOIN places pl ON pc.place_id = pl.id';
                $where[] = 'pl.city_id = :city_id';
                $params['city_id'] = $cityIdFilter;
            }
            // Discussions do not have forum category filter
            if (null === $categoryIdFilter) {
                $whereSql = implode(' AND ', $where);
                $subqueries[] = "SELECT 
                    'place_comment' as type,
                    pc.id as id,
                    pc.created_at as activity_at,
                    pc.author_id as author_id,
                    NULL as title,
                    pc.body as body,
                    pc.place_id as source_id,
                    pc.parent_id as parent_source_id
                FROM place_comments pc {$joins} WHERE {$whereSql}";
            }
        }

        if (empty($subqueries)) {
            return [
                'items' => [],
                'pagination' => [
                    'nextCursor' => null,
                    'hasNextPage' => false,
                ],
            ];
        }

        // Combine with UNION and apply pagination / cursor
        $combinedSql = implode(' UNION ALL ', $subqueries);

        $outerSql = "SELECT * FROM ({$combinedSql}) as combined";

        if (null !== $cursor) {
            $outerSql .= ' WHERE activity_at < :cursor_activity_at OR (activity_at = :cursor_activity_at AND id < :cursor_id)';
            $params['cursor_activity_at'] = $cursor['activityAt'];
            $params['cursor_id'] = $cursor['id'];
        }

        $outerSql .= ' ORDER BY activity_at DESC, id DESC LIMIT :limit';
        $params['limit'] = $limit + 1;

        $rows = $this->connection->fetchAllAssociative($outerSql, $params, [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        $hasNextPage = \count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        // Collect author IDs for batch lookup
        $authorIds = array_map(static fn ($r) => Uuid::fromString((string) $r['author_id']), $rows);
        $profiles = $this->authorProfileLookup->getProfiles($authorIds);

        $items = [];
        foreach ($rows as $row) {
            $authorIdStr = (string) $row['author_id'];
            $author = $profiles[$authorIdStr] ?? [
                'id' => $authorIdStr,
                'displayName' => 'Usunięty użytkownik',
                'initials' => 'U',
            ];

            // Excerpt length limit
            $body = null !== $row['body'] ? (string) $row['body'] : '';
            $excerpt = mb_strlen($body) > 200 ? mb_substr($body, 0, 200).'...' : $body;

            $items[] = [
                'type' => (string) $row['type'],
                'id' => (string) $row['id'],
                'activityAt' => (new \DateTimeImmutable((string) $row['activity_at']))->format(\DateTimeInterface::ATOM),
                'author' => $author,
                'title' => null !== $row['title'] ? (string) $row['title'] : null,
                'excerpt' => $excerpt,
                'sourceId' => (string) $row['source_id'],
                'parentSourceId' => null !== $row['parent_source_id'] ? (string) $row['parent_source_id'] : null,
            ];
        }

        $nextCursor = null;
        if (!empty($rows)) {
            $lastRow = end($rows);
            $nextCursor = base64_encode((string) json_encode([
                'id' => (string) $lastRow['id'],
                'activityAt' => (string) $lastRow['activity_at'],
            ]));
        }

        return [
            'items' => $items,
            'pagination' => [
                'nextCursor' => $nextCursor,
                'hasNextPage' => $hasNextPage,
            ],
        ];
    }
}
