<?php

declare(strict_types=1);

namespace App\Tests\Unit\Probe;

use App\Probe\HttpProbe;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpProbeTest extends \PHPUnit\Framework\TestCase
{
    public function testUpOn2xx(): void
    {
        $probe = $this->probe(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));

        $outcome = $probe->run('https://example.com/health');

        self::assertTrue($outcome->up);
        self::assertSame(200, $outcome->statusCode);
        self::assertNull($outcome->error);
        self::assertSame('up', $outcome->status());
    }

    public function testDownOn4xx(): void
    {
        $probe = $this->probe(static fn (): MockResponse => new MockResponse('', ['http_code' => 404]));

        $outcome = $probe->run('https://example.com/missing');

        self::assertFalse($outcome->up);
        self::assertSame(404, $outcome->statusCode);
        self::assertSame('HTTP 404', $outcome->error);
        self::assertSame('down', $outcome->status());
    }

    public function testFallsBackToGetWhenHeadIsNotAllowed(): void
    {
        $methods = [];
        $probe = $this->probe(static function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('', ['http_code' => $method === 'HEAD' ? 405 : 200]);
        });

        $outcome = $probe->run('https://example.com/head-less');

        self::assertTrue($outcome->up);
        self::assertSame(200, $outcome->statusCode);
        self::assertSame(['HEAD', 'GET'], $methods);
    }

    public function testTransportErrorIsDown(): void
    {
        $probe = $this->probe(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection timed out']));

        $outcome = $probe->run('https://example.com/unreachable');

        self::assertFalse($outcome->up);
        self::assertNull($outcome->statusCode);
        self::assertSame('Connection timed out', $outcome->error);
    }

    public function testRetriesUntilSuccess(): void
    {
        $calls = 0;
        $probe = $this->probe(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => $calls === 1 ? 500 : 200]);
        }, retryCount: 2);

        $outcome = $probe->run('https://example.com/flaky');

        self::assertTrue($outcome->up);
        self::assertSame(2, $calls);
    }

    public function testRetriesExhausted(): void
    {
        $calls = 0;
        $probe = $this->probe(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        }, retryCount: 3);

        $outcome = $probe->run('https://example.com/down');

        self::assertFalse($outcome->up);
        self::assertSame('HTTP 503', $outcome->error);
        self::assertSame(3, $calls);
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $factory
     */
    private function probe(callable $factory, int $retryCount = 1): HttpProbe
    {
        return new HttpProbe(new MockHttpClient($factory), 1_000, $retryCount, 0);
    }
}
