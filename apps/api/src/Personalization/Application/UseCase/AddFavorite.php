<?php

declare(strict_types=1);

namespace App\Personalization\Application\UseCase;

use App\Identity\Domain\User;
use App\Personalization\Application\FavoriteRepository;
use App\Personalization\Domain\Favorite;
use App\Personalization\Domain\PublishedPlaceLookup;
use App\Shared\Application\Clock;
use App\Shared\Application\TransactionManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

final class AddFavorite
{
    public function __construct(
        private readonly FavoriteRepository $favoriteRepository,
        private readonly PublishedPlaceLookup $placeLookup,
        private readonly TransactionManager $transactionManager,
        private readonly Clock $clock,
    ) {
    }

    public function execute(User $user, Uuid $placeId): Favorite
    {
        if (!$this->placeLookup->existsAndPublished($placeId)) {
            throw new \InvalidArgumentException('PLACE_NOT_FOUND');
        }

        try {
            return $this->transactionManager->transactional(function () use ($user, $placeId) {
                $existing = $this->favoriteRepository->findByUserAndPlace($user->getId(), $placeId);
                if (null !== $existing) {
                    return $existing;
                }

                $favorite = new Favorite($user, $placeId, $this->clock->now());
                $this->favoriteRepository->save($favorite);

                return $favorite;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->favoriteRepository->findByUserAndPlace($user->getId(), $placeId);
            if (null !== $existing) {
                return $existing;
            }
            throw $e;
        }
    }
}
