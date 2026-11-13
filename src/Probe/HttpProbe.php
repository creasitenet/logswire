<?php

declare(strict_types=1);

namespace App\Probe;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One probe cycle: HEAD first, retries in-cycle, GET fallback when the server
 * refuses HEAD (405/501). A monitor is UP on any 2xx/3xx final status.
 */
final class HttpProbe
{
    private const USER_AGENT = 'LogswireUptime/1.0';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutMs,
        private readonly int $retryCount,
        private readonly int $retryDelaySeconds,
    ) {
    }

    public function run(string $url): ProbeOutcome
    {
        $last = null;

        for ($attempt = 1; $attempt <= max(1, $this->retryCount); ++$attempt) {
            $last = $this->once($url, 'HEAD');
            if ($last->up) {
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
        try {
            $response = $this->httpClient->request($method, $url, [
                'timeout' => max(1, $this->timeoutMs) / 1000,
                'max_redirects' => 10,
                'headers' => ['User-Agent' => self::USER_AGENT],
            ]);
            $statusCode = $response->getStatusCode();
            $response->getHeaders(false);

            if (\in_array($statusCode, [405, 501], true) && $method === 'HEAD') {
                return $this->once($url, 'GET');
            }

            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $up = $statusCode >= 200 && $statusCode <= 399;

            return new ProbeOutcome($up, $statusCode, $ms, $up ? null : \sprintf('HTTP %d', $statusCode));
        } catch (TransportExceptionInterface $e) {
            return new ProbeOutcome(false, null, (int) ((hrtime(true) - $started) / 1_000_000), $e->getMessage());
        } catch (HttpExceptionInterface $e) {
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $statusCode = $e->getResponse()->getStatusCode();

            return new ProbeOutcome(false, $statusCode, $ms, \sprintf('HTTP %d', $statusCode));
        }
    }
}
