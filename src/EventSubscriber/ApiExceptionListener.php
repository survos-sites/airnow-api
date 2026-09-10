<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Survos\AirNowBundle\Exception\AirNowException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionListener
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof AirNowException ? 502 : 500);
        $message = match ($status) {
            400, 422 => 'Use the documented ZIP parameter with five digits; only JSON is supported.',
            404 => 'Unknown endpoint.', 405 => 'Only GET requests are supported.',
            429 => 'Too many requests. Try again later.',
            502 => 'AirNow is unavailable or returned an invalid response.',
            503 => 'The service is temporarily unavailable.',
            default => 'An internal error occurred.',
        };
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];
        $event->setResponse(new JsonResponse(['error' => $message], $status, [...$headers, 'Cache-Control' => 'no-store']));
    }
}
