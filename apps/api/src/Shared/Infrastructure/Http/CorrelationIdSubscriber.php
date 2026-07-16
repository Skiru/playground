<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdSubscriber
{
    public const ATTRIBUTE = '_correlation_id';
    public const HEADER = 'X-Correlation-ID';

    #[AsEventListener(priority: 100)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $supplied = $request->headers->get(self::HEADER);
        $correlationId = \is_string($supplied) && Uuid::isValid($supplied)
            ? $supplied
            : Uuid::v7()->toRfc4122();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
    }

    #[AsEventListener(priority: -100)]
    public function onResponse(ResponseEvent $event): void
    {
        $correlationId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (\is_string($correlationId)) {
            $event->getResponse()->headers->set(self::HEADER, $correlationId);
        }
    }
}
