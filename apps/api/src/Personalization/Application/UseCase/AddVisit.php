<?php

declare(strict_types=1);

namespace App\Personalization\Application\UseCase;

use App\Identity\Domain\User;
use App\Personalization\Application\VisitRepository;
use App\Personalization\Domain\PublishedPlaceLookup;
use App\Personalization\Domain\Visit;
use App\Shared\Application\Clock;
use Symfony\Component\Uid\Uuid;

final class AddVisit
{
    public function __construct(
        private readonly VisitRepository $visitRepository,
        private readonly PublishedPlaceLookup $placeLookup,
        private readonly Clock $clock,
    ) {
    }

    public function execute(User $user, Uuid $placeId, \DateTimeImmutable $visitedOn, ?string $note): Visit
    {
        if (!$this->placeLookup->existsAndPublished($placeId)) {
            throw new \InvalidArgumentException('PLACE_NOT_FOUND');
        }

        $visit = new Visit($user, $placeId, $visitedOn, $note, $this->clock->now());
        $this->visitRepository->save($visit);

        return $visit;
    }
}
