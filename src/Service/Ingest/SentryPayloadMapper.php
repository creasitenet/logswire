<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * Maps a Sentry event JSON payload to issue/event fields.
 */
final class SentryPayloadMapper
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     level: int,
     *     message: string,
     *     fingerprint: string,
     *     title: string,
     *     culprit: ?string,
     *     exceptionType: ?string,
     *     exceptionValue: ?string,
     *     eventTimestamp: ?\DateTimeImmutable
     * }
     */
    public static function map(array $payload, ?string $fingerprintOverride = null): array
    {
        $exception = self::extractTopException($payload);
        $message = self::extractMessage($payload, $exception);

        return [
            'level' => self::parseLevel($payload['level'] ?? null),
            'message' => $message,
            'fingerprint' => self::resolveFingerprint($payload, $exception, $fingerprintOverride),
            'title' => self::buildTitle($exception, $message),
            'culprit' => self::extractCulprit($exception),
            'exceptionType' => $exception['type'] ?? null,
            'exceptionValue' => $exception['value'] ?? null,
            'eventTimestamp' => self::parseTimestamp($payload['timestamp'] ?? null),
        ];
    }

    /**
     * Request / client metadata kept as columns so occurrence lists stay useful
     * after full JSON payloads are trimmed.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     requestMethod: ?string,
     *     requestUrl: ?string,
     *     osName: ?string,
     *     browserName: ?string,
     *     serverName: ?string,
     *     clientIp: ?string,
     *     userAgent: ?string
     * }
     */
    public static function mapContext(array $payload): array
    {
        $contexts = isset($payload['contexts']) && \is_array($payload['contexts']) ? $payload['contexts'] : [];

        $osName = self::contextString($contexts, 'os', 'name')
            ?? self::contextString($contexts, 'runtime', 'name');
        $browserName = self::contextString($contexts, 'browser', 'name')
            ?? self::contextString($contexts, 'client_os', 'name');

        $request = isset($payload['request']) && \is_array($payload['request']) ? $payload['request'] : [];
        $method = isset($request['method']) ? trim((string) $request['method']) : '';
        $url = self::truncateRequestUrl(isset($request['url']) ? (string) $request['url'] : null);

        $serverName = isset($payload['server_name']) ? trim((string) $payload['server_name']) : '';
        if (\strlen($serverName) > 128) {
            $serverName = mb_substr($serverName, 0, 128);
        }

        return [
            'requestMethod' => $method !== '' ? mb_substr($method, 0, 10) : null,
            'requestUrl' => $url,
            'osName' => $osName !== null && $osName !== '' ? mb_substr($osName, 0, 64) : null,
            'browserName' => $browserName !== null && $browserName !== '' ? mb_substr($browserName, 0, 64) : null,
            'serverName' => $serverName !== '' ? $serverName : null,
            'clientIp' => self::extractClientIp($payload),
            'userAgent' => self::extractUserAgent($request),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{type?: string, value?: string, stacktrace?: array<string, mixed>}
     */
    private static function extractTopException(array $payload): array
    {
        if (!isset($payload['exception']['values'][0]) || !\is_array($payload['exception']['values'][0])) {
            return [];
        }

        $first = $payload['exception']['values'][0];

        return [
            'type' => isset($first['type']) ? (string) $first['type'] : null,
            'value' => isset($first['value']) ? (string) $first['value'] : null,
            'stacktrace' => isset($first['stacktrace']) && \is_array($first['stacktrace']) ? $first['stacktrace'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $exception
     */
    private static function extractMessage(array $payload, array $exception): string
    {
        if (isset($payload['message']['formatted']) && \is_string($payload['message']['formatted'])) {
            return mb_substr($payload['message']['formatted'], 0, 4000);
        }
        if (isset($payload['message']) && \is_string($payload['message'])) {
            return mb_substr($payload['message'], 0, 4000);
        }
        if (isset($exception['value']) && \is_string($exception['value'])) {
            return $exception['value'];
        }

        return '(no message)';
    }

    /**
     * @param array<string, mixed> $exception
     */
    private static function buildTitle(array $exception, string $fallback): string
    {
        $type = $exception['type'] ?? null;
        if (\is_string($type) && $type !== '') {
            $val = $exception['value'] ?? null;
            if (\is_string($val) && $val !== '') {
                return mb_substr(sprintf('%s: %s', $type, $val), 0, 512);
            }

            return mb_substr($type, 0, 512);
        }

        return mb_substr($fallback, 0, 512);
    }

    /**
     * @param array<string, mixed> $exception
     */
    private static function extractCulprit(array $exception): ?string
    {
        $frames = $exception['stacktrace']['frames'] ?? null;
        if (!\is_array($frames) || $frames === []) {
            return null;
        }

        $best = null;
        foreach ($frames as $frame) {
            if (!\is_array($frame)) {
                continue;
            }
            $best = $frame;
            if (($frame['in_app'] ?? false) === true) {
                break;
            }
        }
        if ($best === null) {
            return null;
        }

        $file = isset($best['filename']) ? basename((string) $best['filename']) : null;
        $func = isset($best['function']) ? (string) $best['function'] : null;
        if ($file !== null && $func !== null) {
            return sprintf('%s::%s', $file, $func);
        }

        return $file ?? $func;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $exception
     */
    private static function resolveFingerprint(array $payload, array $exception, ?string $override): string
    {
        if ($override !== null && $override !== '') {
            return mb_substr($override, 0, 255);
        }

        if (isset($payload['fingerprint']) && \is_array($payload['fingerprint'])) {
            $parts = array_filter(array_map(static fn ($p) => \is_string($p) ? $p : null, $payload['fingerprint']));

            return hash('sha256', implode('|', $parts));
        }

        $parts = [];
        if (isset($exception['type']) && \is_string($exception['type'])) {
            $parts[] = $exception['type'];
        }
        $frames = $exception['stacktrace']['frames'] ?? [];
        if (\is_array($frames) && $frames !== []) {
            foreach ($frames as $frame) {
                if (!\is_array($frame)) {
                    continue;
                }
                $parts[] = sprintf(
                    '%s:%s:%s',
                    (string) ($frame['filename'] ?? ''),
                    (string) ($frame['function'] ?? ''),
                    (string) ($frame['lineno'] ?? ''),
                );
                if (($frame['in_app'] ?? false) === true) {
                    break;
                }
            }
        }

        if ($parts === []) {
            $parts[] = self::extractMessage($payload, $exception);
        }

        return hash('sha256', implode('|', $parts));
    }

    private static function parseLevel(mixed $raw): int
    {
        // Sentry defaults an absent `level` to "error".
        if (\is_int($raw)) {
            return max(0, min(10, $raw));
        }
        if (\is_string($raw)) {
            return match (strtolower($raw)) {
                'fatal' => 10,
                'error' => 8,
                'warning', 'warn' => 6,
                'info' => 4,
                'debug' => 2,
                'trace' => 0,
                default => 8,
            };
        }

        return 8;
    }

    private static function parseTimestamp(mixed $raw): ?\DateTimeImmutable
    {
        // SDKs send `timestamp` as unix seconds (float); ISO strings are also accepted.
        if (\is_int($raw) || \is_float($raw)) {
            return (new \DateTimeImmutable('@'.(int) $raw))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $contexts
     */
    private static function contextString(array $contexts, string $group, string $field): ?string
    {
        if (!isset($contexts[$group]) || !\is_array($contexts[$group])) {
            return null;
        }
        $value = $contexts[$group][$field] ?? null;
        if (!\is_string($value) && !is_numeric($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private static function truncateRequestUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }

        return mb_strlen($url) <= 512 ? $url : mb_substr($url, 0, 512);
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function extractUserAgent(array $request): ?string
    {
        $headers = $request['headers'] ?? null;
        if (!\is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (!\is_string($name) || strcasecmp($name, 'user-agent') !== 0) {
                continue;
            }
            if (\is_string($value)) {
                $ua = trim($value);
            } elseif (is_numeric($value)) {
                $ua = (string) $value;
            } else {
                return null;
            }
            if ($ua === '') {
                return null;
            }

            return mb_strlen($ua) <= 512 ? $ua : mb_substr($ua, 0, 512);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function extractClientIp(array $payload): ?string
    {
        $candidates = [];

        if (isset($payload['user']) && \is_array($payload['user']) && isset($payload['user']['ip_address'])) {
            $candidates[] = $payload['user']['ip_address'];
        }

        if (isset($payload['request']) && \is_array($payload['request'])) {
            $env = $payload['request']['env'] ?? null;
            if (\is_array($env)) {
                $candidates[] = $env['REMOTE_ADDR'] ?? null;
                $candidates[] = $env['HTTP_X_FORWARDED_FOR'] ?? null;
            }
        }

        foreach ($candidates as $raw) {
            if (!\is_string($raw) && !is_numeric($raw)) {
                continue;
            }
            $ip = trim((string) $raw);
            if ($ip === '' || str_contains($ip, '{{')) {
                continue;
            }
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip, 2)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return mb_strlen($ip) <= 45 ? $ip : mb_substr($ip, 0, 45);
            }
        }

        return null;
    }
}
