<?php

declare(strict_types=1);

namespace App\Personalization\Infrastructure\Doctrine;

use App\Personalization\Application\FavoriteRepository as FavoriteRepositoryPort;
use App\Personalization\Domain\Favorite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Favorite> */
final class FavoriteRepository extends ServiceEntityRepository implements FavoriteRepositoryPort
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    public function findByUserAndPlace(Uuid $userId, Uuid $placeId): ?Favorite
    {
        $favorite = $this->findOneBy([
            'user' => $userId,
            'placeId' => $placeId,
        ]);

        return $favorite instanceof Favorite ? $favorite : null;
    }

    /**
     * @return list<Favorite>
     */
    public function findByUserId(Uuid $userId, int $page = 1, int $pageSize = 20): array
    {
        return $this->findBy(
            ['user' => $userId],
            ['createdAt' => 'DESC'],
            $pageSize,
            ($page - 1) * $pageSize
        );
    }

    public function countByUserId(Uuid $userId): int
    {
        return $this->count(['user' => $userId]);
    }

    public function save(Favorite $favorite): void
    {
        $this->getEntityManager()->persist($favorite);
        $this->getEntityManager()->flush();
    }

    public function remove(Favorite $favorite): void
    {
        $this->getEntityManager()->remove($favorite);
        $this->getEntityManager()->flush();
    }
}
