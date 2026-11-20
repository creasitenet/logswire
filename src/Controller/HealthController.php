<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function check(
        Connection $connection,
        Request $request,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.health_ip')]
        RateLimiterFactory $healthLimiter,
    ): JsonResponse {
        // A trusted proxy — and its own health probe — must not be throttled:
        // bucketing it under the proxy IP would turn a 1 req/s probe into a 429.
        if (!$this->isTrustedProxy($request)) {
            $this->enforceRateLimit($request, $healthLimiter);
        }

        try {
            $connection->executeQuery('SELECT 1');

            return new JsonResponse(['status' => 'ok', 'db' => 'up']);
        } catch (\Throwable $e) {
            $logger->error('Health check failed', ['error' => $e->getMessage()]);

            return new JsonResponse(
                ['status' => 'down', 'db' => 'down'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    /**
     * The client IP is resolved through `TRUSTED_PROXIES`, so a proxied visitor
     * reports its real address; only the proxy itself (no `X-Forwarded-For`, or
     * its own probe) resolves to an address in that list.
     */
    private function isTrustedProxy(Request $request): bool
    {
        $clientIp = $request->getClientIp();
        $trustedProxies = Request::getTrustedProxies();

        return null !== $clientIp
            && [] !== $trustedProxies
            && IpUtils::checkIp($clientIp, $trustedProxies);
    }

    private function enforceRateLimit(Request $request, RateLimiterFactory $healthLimiter): void
    {
        $limiter = $healthLimiter->create('ip:'.($request->getClientIp() ?? 'unknown'));
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retry = $limit->getRetryAfter();

            throw new TooManyRequestsHttpException(max(1, $retry->getTimestamp() - time()), 'rate_limited');
        }
    }
}
