<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * Removes credentials and other high-risk fields from the raw Sentry payload
 * before it is stored. The mapped columns (message, stacktrace, request URL…)
 * are built from the untouched event, so debugging data stays intact.
 *
 * Values are replaced by "[Filtered]" (the same marker Sentry uses) instead of
 * dropping keys, which keeps payloads readable and preserves their shape.
 */
final class PayloadScrubber
{
    public const string REDACTED = '[Filtered]';

    private const int MAX_DEPTH = 12;

    /**
     * Keys are normalised (lowercase, non-alphanumeric removed) before comparison.
     * A key is sensitive when it is equal to, or ends with, one of these values.
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEYS = [
        'password',
        'passwd',
        'passphrase',
        'secret',
        'token',
        'authorization',
        'cookie',
        'cookies',
        'session',
        'sessionid',
        'phpsessid',
        'apikey',
        'privatekey',
        'secretkey',
        'creditcard',
        'cardnumber',
        'cvv',
        'cvc',
        'ssn',
        'iban',
        'bankaccount',
        'routingnumber',
        'otp',
        'totp',
        'awsaccesskeyid',
        'awssecretaccesskey',
    ];

    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function scrub(array $payload): array
    {
        if (!$this->enabled) {
            return $payload;
        }

        /** @var array<string, mixed> $scrubbed */
        $scrubbed = $this->scrubValue($payload, 0);

        return $scrubbed;
    }

    private function scrubValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::REDACTED;
        }

        if (!\is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $child) {
            if (\is_string($key) && $this->isSensitive($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            // Some SDKs send request.headers as a list of [name, value] pairs
            // instead of an object. Detect that shape so header names are checked.
            if (\is_string($key) && 'headers' === $this->normalise($key) && \is_array($child)) {
                $out[$key] = $this->scrubHeaders($child);

                continue;
            }

            $out[$key] = $this->scrubValue($child, $depth + 1);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<array-key, mixed>
     */
    private function scrubHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (\is_int($name) && \is_array($value) && 2 === \count($value) && \array_key_exists(0, $value) && \is_string($value[0])) {
                $out[$name] = [
                    $value[0],
                    $this->isSensitive($value[0]) ? self::REDACTED : $this->scrubValue($value[1], 1),
                ];

                continue;
            }

            if (\is_string($name) && $this->isSensitive($name)) {
                $out[$name] = self::REDACTED;

                continue;
            }

            $out[$name] = $this->scrubValue($value, 1);
        }

        return $out;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = $this->normalise($key);
        if ('' === $normalised) {
            return false;
        }

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalised === $sensitive || str_ends_with($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
    }
}
