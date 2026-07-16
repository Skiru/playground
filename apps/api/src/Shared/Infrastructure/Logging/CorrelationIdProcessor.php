<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use App\Shared\Application\CorrelationId;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class CorrelationIdProcessor
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = $this->requestStack->getCurrentRequest()?->attributes->get(CorrelationId::ATTRIBUTE);
        if (\is_string($correlationId)) {
            $record->extra['correlation_id'] = $correlationId;
        }

        return $record;
    }
}
