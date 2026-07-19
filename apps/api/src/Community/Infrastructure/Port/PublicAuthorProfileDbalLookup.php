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
