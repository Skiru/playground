<?php

declare(strict_types=1);

namespace App\Personalization\Infrastructure;

use App\Personalization\Domain\PublishedPlaceLookup;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class PublishedPlaceDbalLookup implements PublishedPlaceLookup
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function existsAndPublished(Uuid $placeId): bool
    {
        $sql = 'SELECT 1 FROM places WHERE id = :id AND status = :status';
        $result = $this->connection->fetchOne($sql, [
            'id' => $placeId->toString(),
            'status' => 'published',
        ]);

        return false !== $result;
    }
}
