<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Return JSON error bodies for /api/* requests so SDKs receive parseable
 * responses instead of HTML pages.
 *
 * Priority must be lower than {@see ErrorListener::onKernelException} (-128)
 * so we replace the HTML error page instead of being overwritten by it.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly bool $debug = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -129]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface
            ? $throwable->getStatusCode()
            : 500;

        $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'server_error';
        if ($status >= 500 && !$this->debug) {
            $message = 'server_error';
        }

        $event->setResponse(new JsonResponse(['error' => $message], $status));
    }
}
