<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Port;

use App\Community\Application\Port\PublicAuthorProfileLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PublicAuthorProfileDbalLookup implements PublicAuthorProfileLookup
{
    public function __construct(private Connection $connection)
    {
    }

    public function getProfile(Uuid $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, display_name FROM users WHERE id = :id',
            ['id' => $userId->toRfc4122()]
        );

        if (false === $row) {
            return null;
        }

        $displayName = (string) $row['display_name'];
        $initials = $this->calculateInitials($displayName);

        return [
            'id' => (string) $row['id'],
            'displayName' => $displayName,
            'initials' => $initials,
        ];
    }

    public function getProfiles(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $uniqueIds = array_unique(array_map(static fn (Uuid $id) => $id->toRfc4122(), $userIds));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name FROM users WHERE id IN (:ids)',
            ['ids' => $uniqueIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $profiles = [];
        foreach ($rows as $row) {
            $displayName = (string) $row['display_name'];
            $initials = $this->calculateInitials($displayName);
            $idStr = (string) $row['id'];
            $profiles[$idStr] = [
                'id' => $idStr,
                'displayName' => $displayName,
                'initials' => $initials,
            ];
        }

        return $profiles;
    }

    private function calculateInitials(string $displayName): string
    {
        $words = preg_split('/\s+/', trim($displayName));
        $initials = '';
        if ($words) {
            foreach ($words as $word) {
                if ('' !== $word) {
                    $initials .= mb_strtoupper(mb_substr($word, 0, 1));
                }
            }
        }
        $initials = mb_substr($initials, 0, 2);

        return '' === $initials ? 'U' : $initials;
    }
}
