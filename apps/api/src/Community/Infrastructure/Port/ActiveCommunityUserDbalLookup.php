<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Port;

use App\Community\Application\Port\ActiveCommunityUserLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ActiveCommunityUserDbalLookup implements ActiveCommunityUserLookup
{
    public function __construct(private Connection $connection)
    {
    }

    public function isActiveUser(Uuid $userId): bool
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $userId->toRfc4122()]
        );

        return 'ACTIVE' === $status;
    }
}
