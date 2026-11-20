<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Retention\RetentionRunner;
use App\Service\Retention\RetentionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthzReportsOkWithoutRunningRetention(): void
    {
        $client = static::createClient();

        $runner = $this->createMock(RetentionRunner::class);
        $runner->expects(self::never())->method('maybeRun');
        $runner->expects(self::never())->method('runNow');
        static::getContainer()->set(RetentionRunner::class, $runner);

        $retention = $this->createMock(RetentionService::class);
        $retention->expects(self::never())->method('runOnce');
        static::getContainer()->set(RetentionService::class, $retention);

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok', 'db' => 'up'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testTrustedProxyIsExemptFromRateLimit(): void
    {
        $client = static::createClient();

        // The limiter must not even be consulted for a trusted proxy.
        $limiter = $this->createMock(RateLimiterFactory::class);
        $limiter->expects(self::never())->method('create');
        static::getContainer()->set('limiter.health_ip', $limiter);

        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        try {
            $client->request('GET', '/healthz');
        } finally {
            Request::setTrustedProxies([], -1);
        }

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok', 'db' => 'up'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
