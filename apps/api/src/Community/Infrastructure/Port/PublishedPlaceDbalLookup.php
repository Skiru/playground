<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Port;

use App\Community\Application\Port\PublishedPlaceLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PublishedPlaceDbalLookup implements PublishedPlaceLookup
{
    public function __construct(private Connection $connection)
    {
    }

    public function isPublished(Uuid $placeId): bool
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM places WHERE id = :id',
            ['id' => $placeId->toRfc4122()]
        );

        return 'published' === $status;
    }
}
