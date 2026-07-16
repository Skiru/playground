<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Shared\Application\Clock;

final readonly class ManagePlace
{
    public function __construct(private AdminPlaceGateway $gateway, private Clock $clock)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->gateway->list();
    }

    /** @param array<string, scalar|null> $data */
    public function createDraft(array $data): string
    {
        return $this->gateway->createDraft($data);
    }

    public function publish(string $id): void
    {
        $problems = $this->gateway->publicationProblems($id);
        if ([] !== $problems) {
            throw new \DomainException('Place is incomplete: '.implode(', ', $problems));
        }
        $this->gateway->changeStatus($id, 'published', $this->clock->now());
    }

    public function unpublish(string $id): void
    {
        $this->gateway->changeStatus($id, 'draft', null);
    }

    public function submitForReview(string $id): void
    {
        $this->gateway->changeStatus($id, 'pending_review', null);
    }

    public function archive(string $id): void
    {
        $this->gateway->changeStatus($id, 'archived', null);
    }

    public function markNeedsReverification(string $id): void
    {
        $this->gateway->changeStatus($id, 'needs_reverification', null);
    }

    public function markTemporarilyClosed(string $id): void
    {
        $this->gateway->changeStatus($id, 'temporarily_closed', null);
    }
}
