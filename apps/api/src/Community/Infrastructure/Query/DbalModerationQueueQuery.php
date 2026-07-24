<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Query;

use App\Community\Application\Port\ModerationQueueQuery;
use App\Community\Application\Port\PublicAuthorProfileLookup;
use App\Community\Domain\Moderation\TargetType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DbalModerationQueueQuery implements ModerationQueueQuery
{
    public function __construct(
        private Connection $connection,
        private PublicAuthorProfileLookup $authorProfileLookup,
    ) {
    }

    public function getQueue(?string $statusFilter, ?string $cursor, int $limit): array
    {
        $params = [
            'limit' => $limit + 1,
        ];

        $where = [];
        if (null !== $statusFilter && '' !== trim($statusFilter)) {
            $where[] = 'status = :status';
            $params['status'] = $statusFilter;
        }

        // Decode and parse cursor
        $cursorData = null;
        if (null !== $cursor && '' !== trim($cursor)) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $cursorData = json_decode($decoded, true);
            }
        }

        // Validate statusFilter in cursor matches requested statusFilter
        if (null !== $cursorData) {
            if (($cursorData['statusFilter'] ?? null) !== $statusFilter) {
                $cursorData = null;
            }
        }

        if (null !== $cursorData) {
            $lastPriority = (int) $cursorData['priority'];
            $lastCreatedAt = (string) $cursorData['createdAt'];
            $lastId = (string) $cursorData['id'];

            $where[] = '(
                (CASE status 
                    WHEN \'OPEN\' THEN 1 
                    WHEN \'IN_REVIEW\' THEN 2 
                    WHEN \'RESOLVED\' THEN 3 
                    WHEN \'DISMISSED\' THEN 4 
                    ELSE 5 END > :last_priority)
                OR (
                    CASE status 
                        WHEN \'OPEN\' THEN 1 
                        WHEN \'IN_REVIEW\' THEN 2 
                        WHEN \'RESOLVED\' THEN 3 
                        WHEN \'DISMISSED\' THEN 4 
                        ELSE 5 END = :last_priority 
                    AND created_at < :last_created_at
                )
                OR (
                    CASE status 
                        WHEN \'OPEN\' THEN 1 
                        WHEN \'IN_REVIEW\' THEN 2 
                        WHEN \'RESOLVED\' THEN 3 
                        WHEN \'DISMISSED\' THEN 4 
                        ELSE 5 END = :last_priority 
                    AND created_at = :last_created_at 
                    AND id < :last_id
                )
            )';
            $params['last_priority'] = $lastPriority;
            $params['last_created_at'] = $lastCreatedAt;
            $params['last_id'] = $lastId;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Deterministic order: status priority (OPEN, IN_REVIEW, RESOLVED, DISMISSED), creation timestamp, unique ID
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM content_reports {$whereSql} 
             ORDER BY CASE status 
                WHEN 'OPEN' THEN 1 
                WHEN 'IN_REVIEW' THEN 2 
                WHEN 'RESOLVED' THEN 3 
                WHEN 'DISMISSED' THEN 4 
                ELSE 5 END ASC, 
             created_at DESC, id DESC 
             LIMIT :limit",
            $params,
            [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]
        );

        // Bounded count query (without cursor condition)
        $countParams = [];
        $countWhere = [];
        if (null !== $statusFilter && '' !== trim($statusFilter)) {
            $countWhere[] = 'status = :status';
            $countParams['status'] = $statusFilter;
        }
        $countWhereSql = !empty($countWhere) ? 'WHERE ' . implode(' AND ', $countWhere) : '';
        $totalItems = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM content_reports {$countWhereSql}",
            $countParams
        );

        $hasNextPage = false;
        if (\count($rows) > $limit) {
            $hasNextPage = true;
            array_pop($rows);
        }

        // 1. Group target IDs by type to batch load
        $targetIdsByType = [
            'REVIEW' => [],
            'PLACE_COMMENT' => [],
            'FORUM_THREAD' => [],
            'FORUM_POST' => [],
        ];

        $reporterIds = [];
        $allTargetIds = [];

        foreach ($rows as $row) {
            $tType = (string) $row['target_type'];
            $tId = (string) $row['target_id'];
            $reporterIds[] = Uuid::fromString((string) $row['reporter_id']);
            $allTargetIds[] = Uuid::fromString($tId);

            if (isset($targetIdsByType[$tType])) {
                $targetIdsByType[$tType][] = $tId;
            }
        }

        // 2. Batch load target entities
        $reviews = [];
        $placeIds = [];
        if (!empty($targetIdsByType['REVIEW'])) {
            $reviewRows = $this->connection->fetchAllAssociative(
                'SELECT id, body, rating, status, author_id, place_id FROM reviews WHERE id IN (:ids)',
                ['ids' => $targetIdsByType['REVIEW']],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($reviewRows as $r) {
                $reviews[$r['id']] = $r;
                $placeIds[] = $r['place_id'];
            }
        }

        $comments = [];
        if (!empty($targetIdsByType['PLACE_COMMENT'])) {
            $commentRows = $this->connection->fetchAllAssociative(
                'SELECT id, body, status, author_id, place_id FROM place_comments WHERE id IN (:ids)',
                ['ids' => $targetIdsByType['PLACE_COMMENT']],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($commentRows as $c) {
                $comments[(string) $c['id']] = $c;
                $placeIds[] = $c['place_id'];
            }
        }

        $threads = [];
        if (!empty($targetIdsByType['FORUM_THREAD'])) {
            $threadRows = $this->connection->fetchAllAssociative(
                'SELECT id, title, status, author_id, category_id FROM forum_threads WHERE id IN (:ids)',
                ['ids' => $targetIdsByType['FORUM_THREAD']],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($threadRows as $t) {
                $threads[$t['id']] = $t;
            }
        }

        $posts = [];
        $threadIdsForPosts = [];
        if (!empty($targetIdsByType['FORUM_POST'])) {
            $postRows = $this->connection->fetchAllAssociative(
                'SELECT id, body, status, author_id, thread_id FROM forum_posts WHERE id IN (:ids)',
                ['ids' => $targetIdsByType['FORUM_POST']],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($postRows as $p) {
                $posts[$p['id']] = $p;
                $threadIdsForPosts[] = $p['thread_id'];
            }
        }

        // Batch load thread details for posts so we can get category_id / thread title
        $threadsForPosts = [];
        if (!empty($threadIdsForPosts)) {
            $tRows = $this->connection->fetchAllAssociative(
                'SELECT id, title FROM forum_threads WHERE id IN (:ids)',
                ['ids' => array_unique($threadIdsForPosts)],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($tRows as $tr) {
                $threadsForPosts[$tr['id']] = $tr;
            }
        }

        // Batch load place slugs
        $places = [];
        if (!empty($placeIds)) {
            $placeRows = $this->connection->fetchAllAssociative(
                'SELECT id, slug FROM places WHERE id IN (:ids)',
                ['ids' => array_unique($placeIds)],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($placeRows as $pl) {
                $places[$pl['id']] = $pl;
            }
        }

        // Batch load previous moderation history
        $modActionsByTarget = [];
        if (!empty($allTargetIds)) {
            $targetIdStrings = array_map(fn ($id) => $id->toRfc4122(), $allTargetIds);
            $modRows = $this->connection->fetchAllAssociative(
                'SELECT * FROM moderation_actions WHERE target_id IN (:ids) ORDER BY created_at DESC',
                ['ids' => $targetIdStrings],
                ['ids' => ArrayParameterType::STRING]
            );
            foreach ($modRows as $mRow) {
                $modActionsByTarget[$mRow['target_id']][] = [
                    'id' => $mRow['id'],
                    'moderatorId' => $mRow['moderator_id'],
                    'action' => $mRow['action'],
                    'reason' => $mRow['reason'],
                    'createdAt' => (new \DateTimeImmutable($mRow['created_at']))->format(\DateTimeInterface::ATOM),
                    'previousStatus' => $mRow['previous_status'],
                    'resultingStatus' => $mRow['resulting_status'],
                ];
            }
        }

        // Batch load user profiles (reporters + content authors)
        $userIds = $reporterIds;
        foreach ($reviews as $r) {
            $userIds[] = Uuid::fromString($r['author_id']);
        }
        foreach ($comments as $c) {
            $userIds[] = Uuid::fromString($c['author_id']);
        }
        foreach ($threads as $t) {
            $userIds[] = Uuid::fromString($t['author_id']);
        }
        foreach ($posts as $p) {
            $userIds[] = Uuid::fromString($p['author_id']);
        }

        $profiles = $this->authorProfileLookup->getProfiles(array_unique($userIds));

        $items = [];
        foreach ($rows as $row) {
            $rId = (string) $row['id'];
            $reporterIdStr = (string) $row['reporter_id'];
            $targetIdStr = (string) $row['target_id'];
            $targetTypeVal = (string) $row['target_type'];

            $reporter = $profiles[$reporterIdStr] ?? [
                'id' => $reporterIdStr,
                'displayName' => 'Anonimowy reporter',
                'initials' => 'A',
            ];

            // Resolve target content, author, public and admin links
            $originalContent = '[Przedmiot nie istnieje lub usunięty]';
            $authorProfile = null;
            $publicLink = '#';
            $adminLink = '#';

            switch ($targetTypeVal) {
                case 'REVIEW':
                    $rev = $reviews[$targetIdStr] ?? null;
                    if (null !== $rev) {
                        $originalContent = $rev['body'];
                        $authorIdStr = $rev['author_id'];
                        $authorProfile = $profiles[$authorIdStr] ?? null;
                        $slug = $places[$rev['place_id']]['slug'] ?? '';
                        $publicLink = "/miejsca/{$slug}";
                        $adminLink = "/admin?entity=Review&action=detail&id={$targetIdStr}";
                    }
                    break;

                case 'PLACE_COMMENT':
                    $comm = $comments[$targetIdStr] ?? null;
                    if (null !== $comm) {
                        $originalContent = $comm['body'];
                        $authorIdStr = $comm['author_id'];
                        $authorProfile = $profiles[$authorIdStr] ?? null;
                        $slug = $places[$comm['place_id']]['slug'] ?? '';
                        $publicLink = "/miejsca/{$slug}";
                        $adminLink = "/admin?entity=PlaceComment&action=detail&id={$targetIdStr}";
                    }
                    break;

                case 'FORUM_THREAD':
                    $thr = $threads[$targetIdStr] ?? null;
                    if (null !== $thr) {
                        $originalContent = $thr['title'];
                        $authorIdStr = $thr['author_id'];
                        $authorProfile = $profiles[$authorIdStr] ?? null;
                        $publicLink = "/forum/watek/{$targetIdStr}";
                        $adminLink = "/admin?entity=ForumThread&action=detail&id={$targetIdStr}";
                    }
                    break;

                case 'FORUM_POST':
                    $pst = $posts[$targetIdStr] ?? null;
                    if (null !== $pst) {
                        $originalContent = $pst['body'];
                        $authorIdStr = $pst['author_id'];
                        $authorProfile = $profiles[$authorIdStr] ?? null;
                        $publicLink = "/forum/watek/{$pst['thread_id']}";
                        $adminLink = "/admin?entity=ForumPost&action=detail&id={$targetIdStr}";
                    }
                    break;
            }

            $items[] = [
                'id' => $rId,
                'reporterId' => $reporterIdStr,
                'reporter' => $reporter,
                'targetType' => $targetTypeVal,
                'targetId' => $targetIdStr,
                'reason' => (string) $row['reason'],
                'details' => null !== $row['details'] ? (string) $row['details'] : null,
                'status' => (string) $row['status'],
                'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(\DateTimeInterface::ATOM),
                'resolvedAt' => null !== $row['resolved_at'] ? (new \DateTimeImmutable((string) $row['resolved_at']))->format(\DateTimeInterface::ATOM) : null,
                'resolvedBy' => null !== $row['resolved_by'] ? (string) $row['resolved_by'] : null,
                'evidence' => $originalContent,
                'author' => $authorProfile,
                'publicLink' => $publicLink,
                'adminLink' => $adminLink,
                'moderationHistory' => $modActionsByTarget[$targetIdStr] ?? [],
            ];
        }

        $nextCursor = null;
        if (!empty($rows) && $hasNextPage) {
            $lastRow = end($rows);
            $lastStatus = (string) $lastRow['status'];
            $lastPriority = match ($lastStatus) {
                'OPEN' => 1,
                'IN_REVIEW' => 2,
                'RESOLVED' => 3,
                'DISMISSED' => 4,
                default => 5,
            };
            $cursorPayload = [
                'priority' => $lastPriority,
                'createdAt' => (string) $lastRow['created_at'],
                'id' => (string) $lastRow['id'],
                'statusFilter' => $statusFilter,
            ];
            $nextCursor = base64_encode(json_encode($cursorPayload));
        }

        return [
            'items' => $items,
            'pagination' => [
                'nextCursor' => $nextCursor,
                'hasNextPage' => $hasNextPage,
                'totalItems' => $totalItems,
            ],
        ];
    }
}
