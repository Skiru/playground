<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\CorrelationId;
use App\Shared\Application\Exception\ApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class ProblemDetailsSubscriber
{
    #[AsEventListener]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiException) {
            $event->setResponse(new JsonResponse([
                'type' => 'https://familyplaces.example/problems/'.$throwable->getErrorCode(),
                'title' => $throwable->getTitle(),
                'status' => $throwable->getStatusCode(),
                'detail' => $throwable->getMessage(),
                'code' => $throwable->getErrorCode(),
                'correlationId' => $request->attributes->get(CorrelationId::ATTRIBUTE),
            ], $throwable->getStatusCode(), array_merge(['Content-Type' => 'application/problem+json'], $throwable->getHeaders())));

            return;
        }

        if (!$request->getPathInfo() || !str_starts_with($request->getPathInfo(), '/api/v1/') || !$throwable instanceof \InvalidArgumentException) {
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
