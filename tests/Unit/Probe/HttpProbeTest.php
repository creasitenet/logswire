<?php

declare(strict_types=1);

namespace App\Tests\Unit\Probe;

use App\Probe\HttpProbe;
use App\Probe\MetadataEndpointGuard;
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

    public function testDirectMetadataTargetIsBlockedWithoutAnyRequest(): void
    {
        $calls = 0;
        $probe = $this->probe(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 200]);
        });

        $outcome = $probe->run('http://169.254.169.254/latest/meta-data/');

        self::assertFalse($outcome->up);
        self::assertSame(0, $calls);
        self::assertStringStartsWith('blocked:', (string) $outcome->error);
    }

    public function testRedirectToMetadataIsBlockedBeforeFollowingIt(): void
    {
        $requested = [];
        $probe = $this->probe(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'],
            ]);
        });

        $outcome = $probe->run('https://example.com/redirect');

        self::assertFalse($outcome->up);
        self::assertSame(['https://example.com/redirect'], $requested);
        self::assertStringStartsWith('blocked:', (string) $outcome->error);
    }

    public function testFollowsPublicRedirect(): void
    {
        $requested = [];
        $probe = $this->probe(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return $url === 'https://example.com/start'
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/final']])
                : new MockResponse('', ['http_code' => 200]);
        });

        $outcome = $probe->run('https://example.com/start');

        self::assertTrue($outcome->up);
        self::assertSame('https://example.com/final', $requested[1]);
    }

    public function testRedirectLoopIsDown(): void
    {
        $probe = $this->probe(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => '/loop'],
        ]));

        $outcome = $probe->run('https://example.com/loop');

        self::assertFalse($outcome->up);
        self::assertSame('too many redirects', $outcome->error);
    }

    public function testNeverLetsSymfonyFollowRedirectsItself(): void
    {
        $options = null;
        $probe = $this->probe(static function (string $method, string $url, array $given) use (&$options): MockResponse {
            $options = $given;

            return new MockResponse('', ['http_code' => 200]);
        });

        $probe->run('https://example.com/');

        self::assertIsArray($options);
        self::assertSame(0, $options['max_redirects']);
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $factory
     */
    private function probe(callable $factory, int $retryCount = 1): HttpProbe
    {
        return new HttpProbe(
            new MockHttpClient($factory),
            1_000,
            $retryCount,
            0,
            new MetadataEndpointGuard(static fn (string $host): array => ['93.184.216.34']),
        );
    }
}
