<?php

declare(strict_types=1);

namespace App\Personalization\Infrastructure\Doctrine;

use App\Personalization\Application\VisitRepository as VisitRepositoryPort;
use App\Personalization\Domain\Visit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Visit> */
final class VisitRepository extends ServiceEntityRepository implements VisitRepositoryPort
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Visit::class);
    }

    public function findByIdAndUser(Uuid $id, Uuid $userId): ?Visit
    {
        $visit = $this->findOneBy([
            'id' => $id,
            'user' => $userId,
        ]);

        return $visit instanceof Visit ? $visit : null;
    }

    /**
     * @return list<Visit>
     */
    public function findByUserId(Uuid $userId, int $page = 1, int $pageSize = 20): array
    {
        return $this->findBy(
            ['user' => $userId],
            ['visitedOn' => 'DESC', 'createdAt' => 'DESC'],
            $pageSize,
            ($page - 1) * $pageSize
        );
    }

    public function countByUserId(Uuid $userId): int
    {
        return $this->count(['user' => $userId]);
    }

    public function findLastVisitedOnByPlaces(Uuid $userId, array $placeIds): array
    {
        if (empty($placeIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        
        $placeStringIds = array_map(static fn(Uuid $id) => $id->toString(), $placeIds);
        
        $sql = 'SELECT place_id, MAX(visited_on) as last_visited FROM visits WHERE user_id = :user_id AND place_id IN (:place_ids) GROUP BY place_id';
        
        // Let's use Connection::PARAM_STR_ARRAY or bind with string[] type
        $rows = $conn->fetchAllAssociative($sql, [
            'user_id' => $userId->toString(),
            'place_ids' => $placeStringIds,
        ], [
            'place_ids' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['place_id']] = $row['last_visited'];
        }

        return $result;
    }

    public function save(Visit $visit): void
    {
        $this->getEntityManager()->persist($visit);
        $this->getEntityManager()->flush();
    }

    public function remove(Visit $visit): void
    {
        $this->getEntityManager()->remove($visit);
        $this->getEntityManager()->flush();
    }
}
