<?php

declare(strict_types=1);

namespace App\Places\Infrastructure\Doctrine;

use App\Places\Application\PlaceRepository as PlaceRepositoryPort;
use App\Places\Domain\Place;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class PlaceRepository implements PlaceRepositoryPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Place $place): void
    {
        $this->connection->update('places', [
            'status' => $place->status()->value,
            'verification_status' => $place->verificationStatus()->value,
            'published_at' => $place->publishedAt(),
            'updated_at' => $place->updatedAt(),
        ], ['id' => $place->id()->toRfc4122()], [
            'published_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }
}
