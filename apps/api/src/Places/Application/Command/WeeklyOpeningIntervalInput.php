<?php

declare(strict_types=1);

namespace App\Places\Application\Command;

final readonly class WeeklyOpeningIntervalInput
{
    public function __construct(public int $weekday, public int $sequence, public string $opensAt, public string $closesAt, public bool $closesNextDay)
    {
        if ($weekday < 1 || $weekday > 7 || $sequence < 1 || !self::isTime($opensAt) || !self::isTime($closesAt)) {
            throw new \InvalidArgumentException('Invalid weekly opening interval.');
        }
    }

    private static function isTime(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!H:i', $value);

        return false !== $parsed && $parsed->format('H:i') === $value;
    }
}
