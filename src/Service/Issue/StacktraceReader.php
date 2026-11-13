<?php

declare(strict_types=1);

namespace App\Service\Issue;

final class StacktraceReader
{
    /**
     * @return list<array{filename: ?string, function: ?string, lineno: ?int, abs_path: ?string, in_app: bool}>
     */
    public function frames(?string $payloadJson): array
    {
        if ($payloadJson === null || $payloadJson === '') {
            return [];
        }

        try {
            $data = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($data)) {
            return [];
        }

        $rawFrames = $this->locateFrames($data);
        $out = [];
        foreach ($rawFrames as $frame) {
            if (!\is_array($frame)) {
                continue;
            }
            $out[] = [
                'filename' => isset($frame['filename']) ? (string) $frame['filename'] : null,
                'function' => isset($frame['function']) ? (string) $frame['function'] : (isset($frame['module']) ? (string) $frame['module'] : null),
                'lineno' => isset($frame['lineno']) ? (int) $frame['lineno'] : null,
                'abs_path' => isset($frame['abs_path']) ? (string) $frame['abs_path'] : null,
                'in_app' => (bool) ($frame['in_app'] ?? false),
            ];
        }

        return array_reverse($out);
    }

    /**
     * Stable fingerprint of the normalized frames, used to spot divergent
     * stacktraces for the same issue even after payloads are trimmed.
     */
    public function hash(?string $payloadJson): ?string
    {
        $frames = $this->frames($payloadJson);
        if ($frames === []) {
            return null;
        }

        $lines = [];
        foreach ($frames as $frame) {
            $file = $frame['filename'] ?? $frame['abs_path'] ?? '?';
            $lines[] = $file.':'.($frame['lineno'] ?? '?').':'.($frame['function'] ?? '?');
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** @param array<string, mixed> $data */
    private function locateFrames(array $data): array
    {
        $values = $data['exception']['values'] ?? null;
        if (\is_array($values) && $values !== []) {
            $first = $values[0];
            if (\is_array($first) && isset($first['stacktrace']['frames']) && \is_array($first['stacktrace']['frames'])) {
                return $first['stacktrace']['frames'];
            }
        }

        $threads = $data['threads']['values'] ?? null;
        if (\is_array($threads) && $threads !== []) {
            $first = $threads[0];
            if (\is_array($first) && isset($first['stacktrace']['frames']) && \is_array($first['stacktrace']['frames'])) {
                return $first['stacktrace']['frames'];
            }
        }

        return [];
    }
}
