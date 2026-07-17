<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\CorrelationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class ProblemDetailsSubscriber
{
    #[AsEventListener]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->getPathInfo() || !str_starts_with($request->getPathInfo(), '/api/v1/') || !$event->getThrowable() instanceof \InvalidArgumentException) {
            return;
        }
        $event->setResponse(new JsonResponse([
            'type' => 'https://familyplaces.example/problems/invalid_query',
            'title' => 'Invalid query parameters.',
            'status' => 400,
            'detail' => $event->getThrowable()->getMessage(),
            'code' => 'invalid_query',
            'correlationId' => $request->attributes->get(CorrelationId::ATTRIBUTE),
        ], 400, ['Content-Type' => 'application/problem+json']));
    }
}
