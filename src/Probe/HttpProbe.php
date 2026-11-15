<?php

declare(strict_types=1);

namespace App\Probe;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One probe cycle: HEAD first, retries in-cycle, GET fallback when the server
 * refuses HEAD (405/501). A monitor is UP on any 2xx/3xx final status.
 *
 * Redirects are followed manually (max 10 hops, http(s) only) so every hop can
 * be checked against MetadataEndpointGuard before a request is sent. Automatic
 * redirects would reach the metadata endpoint before we could inspect it.
 */
final class HttpProbe
{
    private const USER_AGENT = 'LogswireUptime/1.0';
    private const MAX_REDIRECTS = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutMs,
        private readonly int $retryCount,
        private readonly int $retryDelaySeconds,
        private readonly MetadataEndpointGuard $guard = new MetadataEndpointGuard(),
    ) {
    }

    public function run(string $url): ProbeOutcome
    {
        $last = null;

        for ($attempt = 1; $attempt <= max(1, $this->retryCount); ++$attempt) {
            $last = $this->once($url, 'HEAD');

            // A blocked target is a policy decision, not a flaky network read:
            // retrying would hit the same wall and delay the DOWN transition.
            if ($last->up || str_starts_with((string) $last->error, 'blocked:')) {
                break;
            }

            if ($attempt < max(1, $this->retryCount)) {
                sleep(max(0, $this->retryDelaySeconds));
            }
        }

        \assert($last instanceof ProbeOutcome);

        return new ProbeOutcome(
            $last->up,
            $last->statusCode,
            $last->responseMs,
            $last->error,
        );
    }

    private function once(string $url, string $method): ProbeOutcome
    {
        $started = hrtime(true);
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            try {
                $this->guard->assertAllowed($current);
            } catch (BlockedTargetException $e) {
                return new ProbeOutcome(false, null, $this->elapsedMs($started), 'blocked: '.$e->getMessage());
            }

            try {
                $response = $this->httpClient->request($method, $current, [
                    'timeout' => $this->remainingTimeout($started),
                    'max_redirects' => 0,
                    'headers' => ['User-Agent' => self::USER_AGENT],
                ]);
                $statusCode = $response->getStatusCode();
                $headers = $response->getHeaders(false);
            } catch (TransportExceptionInterface $e) {
                return new ProbeOutcome(false, null, $this->elapsedMs($started), $e->getMessage());
            } catch (HttpExceptionInterface $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                return new ProbeOutcome(false, $statusCode, $this->elapsedMs($started), \sprintf('HTTP %d', $statusCode));
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $headers['location'][0] ?? null;
                if (!\is_string($location) || $location === '') {
                    // Reachable, but nothing to follow: same outcome as before.
                    return new ProbeOutcome(true, $statusCode, $this->elapsedMs($started), null);
                }

                $current = $this->resolveLocation($current, $location);
                continue;
            }

            if (\in_array($statusCode, [405, 501], true) && $method === 'HEAD') {
                return $this->once($url, 'GET');
            }

            $up = $statusCode >= 200 && $statusCode <= 399;

            return new ProbeOutcome(
                $up,
                $statusCode,
                $this->elapsedMs($started),
                $up ? null : \sprintf('HTTP %d', $statusCode),
            );
        }

        return new ProbeOutcome(false, null, $this->elapsedMs($started), 'too many redirects');
    }

    /**
     * Resolve a Location header against the current URL. Absolute http(s)
     * targets are kept as-is; anything else (including file:) is rejected by
     * the guard on the next hop.
     */
    private function resolveLocation(string $currentUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return $currentUrl;
        }

        if (strtolower((string) parse_url($location, PHP_URL_SCHEME)) !== '') {
            return $location;
        }

        $scheme = (string) parse_url($currentUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($currentUrl, PHP_URL_HOST);
        $port = parse_url($currentUrl, PHP_URL_PORT);
        $authority = $host.(\is_int($port) ? ':'.$port : '');

        if ($scheme === '' || $host === '') {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$authority.$location;
        }

        $basePath = (string) parse_url($currentUrl, PHP_URL_PATH);

        if (str_starts_with($location, '?')) {
            return $scheme.'://'.$authority.($basePath !== '' ? $basePath : '/').$location;
        }

        $relativePath = (string) parse_url($location, PHP_URL_PATH);
        $query = parse_url($location, PHP_URL_QUERY);

        if ($basePath === '' || str_ends_with($basePath, '/')) {
            $directory = $basePath === '' ? '/' : $basePath;
        } else {
            $directory = rtrim(\dirname($basePath), '/').'/';
        }

        $path = $this->normalizePath($directory.$relativePath);
        $suffix = \is_string($query) && $query !== '' ? '?'.$query : '';

        return $scheme.'://'.$authority.$path.$suffix;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);

        return $normalized !== '/' && str_ends_with($path, '/') ? $normalized.'/' : $normalized;
    }

    private function remainingTimeout(int $startedNs): float
    {
        return max(0.1, ($this->timeoutMs - $this->elapsedMs($startedNs)) / 1000);
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) ((hrtime(true) - $startedNs) / 1_000_000);
    }
}
