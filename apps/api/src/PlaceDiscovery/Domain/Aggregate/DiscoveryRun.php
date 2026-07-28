<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain\Aggregate;

final class DiscoveryRun
{
    public function __construct(
        public readonly string $id,
        public readonly string $areaId,
        public readonly string $source,
        public readonly string $release,
        private DiscoveryRunStatus $status = DiscoveryRunStatus::QUEUED,
        private int $discovered = 0,
        private int $inserted = 0,
        private int $updated = 0,
        private int $duplicates = 0,
        private int $skipped = 0,
        private int $failed = 0,
    ) {
    }

    public function status(): DiscoveryRunStatus
    {
        return $this->status;
    }

    public function start(): void
    {
        $this->transition([DiscoveryRunStatus::QUEUED], DiscoveryRunStatus::RUNNING);
    }

    public function complete(): void
    {
        $this->transition([DiscoveryRunStatus::RUNNING], 0 === $this->failed ? DiscoveryRunStatus::COMPLETED : DiscoveryRunStatus::PARTIAL);
    }

    public function fail(): void
    {
        $this->transition([DiscoveryRunStatus::QUEUED, DiscoveryRunStatus::RUNNING], DiscoveryRunStatus::FAILED);
    }

    public function cancel(): void
    {
        $this->transition([DiscoveryRunStatus::QUEUED, DiscoveryRunStatus::RUNNING], DiscoveryRunStatus::CANCELLED);
    }

    public function record(string $classification): void
    {
        ++$this->discovered;
        match ($classification) {
            'inserted' => ++$this->inserted,
            'updated' => ++$this->updated,
            'duplicate' => ++$this->duplicates,
            'skipped' => ++$this->skipped,
            'failed' => ++$this->failed,
            default => throw new \InvalidArgumentException('Unknown discovery classification.'),
        };
    }

    /** @param list<DiscoveryRunStatus> $allowed */
    private function transition(array $allowed, DiscoveryRunStatus $next): void
    {
        if (!\in_array($this->status, $allowed, true)) {
            throw new \DomainException(\sprintf('Discovery run cannot transition from %s to %s.', $this->status->value, $next->value));
        }
        $this->status = $next;
    }
}
