<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\ExternalIdentityRepository as ExternalIdentityRepositoryPort;
use App\Identity\Domain\ExternalIdentity;
use App\Identity\Domain\ExternalIdentityProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExternalIdentity> */
final class ExternalIdentityRepository extends ServiceEntityRepository implements ExternalIdentityRepositoryPort
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalIdentity::class);
    }

    public function findByProviderAndSubject(ExternalIdentityProvider $provider, string $subject): ?ExternalIdentity
    {
        $identity = $this->findOneBy([
            'provider' => $provider->value,
            'providerSubject' => $subject,
        ]);

        return $identity instanceof ExternalIdentity ? $identity : null;
    }

    public function save(ExternalIdentity $identity): void
    {
        $this->getEntityManager()->persist($identity);
        $this->getEntityManager()->flush();
    }
}
