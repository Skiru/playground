<?php

declare(strict_types=1);

namespace App\Places\Application;

use App\Places\Domain\PlaceStatus;
use App\Places\Domain\VerificationStatus;

final readonly class AdminPlaceSummary
{
    /** @var string[] */
    public array $availableActions;

    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public PlaceStatus $status,
        public string $city,
        public int $version,
        public VerificationStatus $verificationStatus = VerificationStatus::UNVERIFIED,
        public ?string $updatedAt = null,
        public ?string $mainPhotoPath = null,
    ) {
        $this->availableActions = match ($this->status) {
            PlaceStatus::DRAFT => ['submit'],
            PlaceStatus::PENDING_REVIEW => ['publish', 'archive'],
            PlaceStatus::PUBLISHED => ['unpublish', 'reverify', 'close'],
            PlaceStatus::NEEDS_REVERIFICATION => ['publish', 'close', 'archive'],
            PlaceStatus::TEMPORARILY_CLOSED => ['publish', 'archive'],
            PlaceStatus::PERMANENTLY_CLOSED => ['archive'],
            PlaceStatus::ARCHIVED => [],
        };
    }
}
