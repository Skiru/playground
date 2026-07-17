<?php

declare(strict_types=1);

namespace App\Personalization\Application\UseCase;

use App\Identity\Domain\User;
use App\Personalization\Application\VisitRepository;
use Symfony\Component\Uid\Uuid;

final class DeleteVisit
{
    public function __construct(
        private readonly VisitRepository $visitRepository,
    ) {
    }

    public function execute(User $user, Uuid $visitId): void
    {
        $visit = $this->visitRepository->findByIdAndUser($visitId, $user->getId());
        if (null !== $visit) {
            $this->visitRepository->remove($visit);
        }
    }
}
