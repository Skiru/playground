<?php

declare(strict_types=1);

namespace App\Personalization\Application\UseCase;

use App\Identity\Domain\User;
use App\Personalization\Application\VisitRepository;
use App\Personalization\Domain\Visit;
use App\Shared\Application\Clock;
use Symfony\Component\Uid\Uuid;

final class UpdateVisit
{
    public function __construct(
        private readonly VisitRepository $visitRepository,
        private readonly Clock $clock,
    ) {
    }

    public function execute(
        User $user,
        Uuid $visitId,
        ?\DateTimeImmutable $visitedOn,
        bool $hasVisitedOn,
        ?string $note,
        bool $hasNote,
    ): Visit {
        $visit = $this->visitRepository->findByIdAndUser($visitId, $user->getId());
        if (null === $visit) {
            throw new \InvalidArgumentException('VISIT_NOT_FOUND');
        }

        $newVisitedOn = $hasVisitedOn ? $visitedOn : $visit->getVisitedOn();
        if (null === $newVisitedOn) {
            throw new \InvalidArgumentException('Visited date cannot be null.');
        }

        $newNote = $hasNote ? $note : $visit->getNote();

        $visit->update($newVisitedOn, $newNote, $this->clock->now());
        $this->visitRepository->save($visit);

        return $visit;
    }
}
