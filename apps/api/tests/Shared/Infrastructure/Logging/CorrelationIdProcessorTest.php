<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use App\Shared\Application\CorrelationId;
use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CorrelationIdProcessorTest extends TestCase
{
    public function testItAddsTheResponseCorrelationIdToLogContext(): void
    {
        $request = Request::create('/');
        $request->attributes->set(CorrelationId::ATTRIBUTE, '019bdf9e-8c02-7aef-b313-708c73d09de8');
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'request');

        $processed = (new CorrelationIdProcessor($requestStack))($record);

        self::assertSame('019bdf9e-8c02-7aef-b313-708c73d09de8', $processed->extra['correlation_id']);
    }
}
