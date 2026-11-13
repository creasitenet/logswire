<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Aligns URL generation (DSN, redirects, emails) with APP_PUBLIC_URL so host/port
 * stay correct when the incoming Host header omits the port (common behind proxies).
 */
final class PublicUrlRequestContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly string $publicUrl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 48]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->publicUrl === '') {
            return;
        }

        $parts = parse_url($this->publicUrl);
        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        $context = $this->router->getContext();
        $this->applyPublicUrl($context, $parts);

        // If the client sent an explicit port, prefer it (LAN IP, alternate bind, etc.).
        $request = $event->getRequest();
        $hostHeader = $request->headers->get('Host', '');
        if (str_contains($hostHeader, ':')) {
            $context->setHost($request->getHost());
            $context->setScheme($request->getScheme());
            $port = $request->getPort();
            if ($request->getScheme() === 'https') {
                $context->setHttpsPort($port);
            } else {
                $context->setHttpPort($port);
            }
        }
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function applyPublicUrl(RequestContext $context, array $parts): void
    {
        $scheme = \is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'http';
        $context->setHost($parts['host']);
        $context->setScheme($scheme);

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $port = (int) $port;

        if ($scheme === 'https') {
            $context->setHttpsPort($port);
            if ($port === 443) {
                $context->setHttpPort(80);
            }
        } else {
            $context->setHttpPort($port);
            if ($port === 80) {
                $context->setHttpsPort(443);
            }
        }
    }
}
