<?php

declare(strict_types=1);

namespace App\Shared\Application;

final class CorrelationId
{
    public const ATTRIBUTE = '_correlation_id';

    private function __construct()
    {
    }
}
