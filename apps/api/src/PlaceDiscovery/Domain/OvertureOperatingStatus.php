<?php

declare(strict_types=1);

namespace App\PlaceDiscovery\Domain;

enum OvertureOperatingStatus: string
{
    case OPEN = 'open';
    case TEMPORARILY_CLOSED = 'temporarily_closed';
    case PERMANENTLY_CLOSED = 'permanently_closed';
    case UNKNOWN = 'unknown';

    public static function normalize(?string $value): ?self
    {
        if (null === $value) {
            return null;
        }

        return self::tryFrom($value) ?? self::UNKNOWN;
    }

    public function clearsPermanentClosureReview(): bool
    {
        return self::OPEN === $this || self::TEMPORARILY_CLOSED === $this;
    }
}
