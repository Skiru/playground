<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain\Aggregate;

final class PlaceCandidate
{
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $externalId,
        private string $name,
        private float $latitude,
        private float $longitude,
        private ?string $suggestedCategory,
        private CandidateStatus $status = CandidateStatus::PENDING,
        private int $version = 1,
        private bool $manuallyEdited = false,
        private bool $newerSourceData = false,
        private ?string $approvedPlaceId = null,
    ) {
        if ('' === trim($name) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \DomainException('Candidate requires a name and valid coordinates.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function latitude(): float
    {
        return $this->latitude;
    }

    public function longitude(): float
    {
        return $this->longitude;
    }

    public function suggestedCategory(): ?string
    {
        return $this->suggestedCategory;
    }

    public function status(): CandidateStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function approvedPlaceId(): ?string
    {
        return $this->approvedPlaceId;
    }

    public function hasNewerSourceData(): bool
    {
        return $this->newerSourceData;
    }

    public function edit(string $name, float $latitude, float $longitude, ?string $category): void
    {
        if (!\in_array($this->status, [CandidateStatus::PENDING, CandidateStatus::NEEDS_MAPPING, CandidateStatus::POSSIBLE_DUPLICATE], true)) {
            throw new \DomainException('Only reviewable candidates may be edited.');
        }
        $this->name = trim($name);
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->suggestedCategory = $category;
        $this->manuallyEdited = true;
        ++$this->version;
    }

    public function approve(string $placeId): void
    {
        $this->transition([CandidateStatus::PENDING, CandidateStatus::POSSIBLE_DUPLICATE], CandidateStatus::APPROVED);
        $this->approvedPlaceId = $placeId;
    }

    public function reject(string $reason): void
    {
        if ('' === trim($reason)) {
            throw new \DomainException('A rejection reason is required.');
        }
        $this->transition([CandidateStatus::PENDING, CandidateStatus::NEEDS_MAPPING, CandidateStatus::POSSIBLE_DUPLICATE], CandidateStatus::REJECTED);
    }

    public function markDuplicate(string $placeId): void
    {
        if ('' === $placeId) {
            throw new \DomainException('A duplicate Place is required.');
        }
        $this->transition([CandidateStatus::PENDING, CandidateStatus::POSSIBLE_DUPLICATE], CandidateStatus::DUPLICATE);
    }

    public function clearDuplicateWarning(): void
    {
        $this->transition([CandidateStatus::POSSIBLE_DUPLICATE], null === $this->suggestedCategory ? CandidateStatus::NEEDS_MAPPING : CandidateStatus::PENDING);
    }

    public function mapCategory(string $category): void
    {
        if (CandidateStatus::NEEDS_MAPPING !== $this->status || '' === trim($category)) {
            throw new \DomainException('Candidate is not awaiting category mapping.');
        }
        $this->suggestedCategory = $category;
        $this->transition([CandidateStatus::NEEDS_MAPPING], CandidateStatus::PENDING);
    }

    public function refresh(string $name, float $latitude, float $longitude, ?string $category): void
    {
        if (CandidateStatus::APPROVED === $this->status) {
            return;
        }
        if ($this->manuallyEdited) {
            $this->newerSourceData = true;
            ++$this->version;

            return;
        }
        $this->name = $name;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->suggestedCategory = $category;
        ++$this->version;
    }

    /** @param list<CandidateStatus> $allowed */
    private function transition(array $allowed, CandidateStatus $next): void
    {
        if (!\in_array($this->status, $allowed, true)) {
            throw new \DomainException(\sprintf('Candidate cannot transition from %s to %s.', $this->status->value, $next->value));
        }
        $this->status = $next;
        ++$this->version;
    }
}
