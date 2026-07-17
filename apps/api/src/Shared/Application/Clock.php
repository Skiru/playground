<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
