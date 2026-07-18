<?php

declare(strict_types=1);

namespace App\Personalization\Application\UseCase;

use App\Identity\Domain\User;
use App\Personalization\Application\FavoriteRepository;
use Symfony\Component\Uid\Uuid;

final class RemoveFavorite
{
    public function __construct(
        private readonly FavoriteRepository $favoriteRepository,
    ) {
    }

    public function execute(User $user, Uuid $placeId): void
    {
        $favorite = $this->favoriteRepository->findByUserAndPlace($user->getId(), $placeId);
        if (null !== $favorite) {
            $this->favoriteRepository->remove($favorite);
        }
    }
}
