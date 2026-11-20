<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline browser security headers.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?Security $security = null,
        private readonly string $publicUrl = '',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;
        $publicHttps = str_starts_with(strtolower($this->publicUrl), 'https://');

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set(
            'Content-Security-Policy',
            "default-src 'self'; "
            ."script-src 'self'; "
            ."style-src 'self'; "
            ."font-src 'self'; "
            ."img-src 'self' data:; "
            ."connect-src 'self'; "
            ."object-src 'none'; "
            ."frame-src 'none'; "
            ."frame-ancestors 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'"
            .($publicHttps ? "; upgrade-insecure-requests" : ''),
        );

        if ($request->isSecure() || $publicHttps) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Stacktraces and payloads can contain PII/credentials: keep them out of
        // shared caches and browser history caches.
        if ($this->security?->getUser() !== null) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
