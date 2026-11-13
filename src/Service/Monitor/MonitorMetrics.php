<?php

declare(strict_types=1);

namespace App\Service\Monitor;

use App\Entity\MetricBucket;
use App\Entity\Monitor;
use App\Probe\MetricBucketWriter;
use App\Repository\MetricBucketRepository;

/**
 * Read model for uptime: turns hour/day buckets into fixed slots for the
 * 24 h / 7 d / 30 d charts. Slots without any bucket stay "empty" so a missing
 * probe is never rendered as 0 % uptime.
 */
final class MonitorMetrics
{
    public const string RANGE_24H = '24h';
    public const string RANGE_7D = '7d';
    public const string RANGE_30D = '30d';

    public function __construct(private readonly MetricBucketRepository $buckets)
    {
    }

    public static function normalizeRange(?string $range): string
    {
        return \in_array($range, [self::RANGE_24H, self::RANGE_7D, self::RANGE_30D], true)
            ? $range
            : self::RANGE_24H;
    }

    /**
     * @return array{
     *     slot_seconds: int,
     *     slots: list<array{start: int, up: int, down: int, probes: int, uptime: float|null, avg_ms: int|null, max_ms: int|null, state: string}>,
     *     up: int,
     *     down: int,
     *     probes: int,
     *     uptime: float|null,
     *     avg_ms: int|null,
     *     max_ms: int|null
     * }
     */
    public function series(Monitor $monitor, string $range, int $now): array
    {
        $window = self::window($range, $now);

        return self::buildSeries($window, $this->buckets->findSeries(
            $monitor,
            $window['granularity'],
            $window['first'],
            $window['limit'],
        ));
    }

    /**
     * 24 hourly slots for every monitor in a single query (monitor list page).
     *
     * @param list<Monitor> $monitors
     *
     * @return array<string, array{
     *     slot_seconds: int,
     *     slots: list<array{start: int, up: int, down: int, probes: int, uptime: float|null, avg_ms: int|null, max_ms: int|null, state: string}>,
     *     up: int,
     *     down: int,
     *     probes: int,
     *     uptime: float|null,
     *     avg_ms: int|null,
     *     max_ms: int|null
     * }>
     */
    public function sparklines(array $monitors, int $now): array
    {
        $ids = array_values(array_map(static fn (Monitor $monitor): string => $monitor->getId(), $monitors));
        $window = self::window(self::RANGE_24H, $now);

        $grouped = [];
        foreach ($this->buckets->findHourlySince($ids, $window['first']) as $bucket) {
            $grouped[$bucket->getMonitor()->getId()][] = $bucket;
        }

        $series = [];
        foreach ($monitors as $monitor) {
            $series[$monitor->getId()] = self::buildSeries($window, $grouped[$monitor->getId()] ?? []);
        }

        return $series;
    }

    /**
     * Daily rollup rows: one row per stored day bucket, newest last.
     *
     * @return list<array{start: int, probes: int, uptime: float|null, avg_ms: int|null, max_ms: int|null}>
     */
    public function dailyRollup(Monitor $monitor, int $now, int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $from = MetricBucketWriter::bucketStart($now, 'day') - ($days - 1) * 86400;

        $rows = [];
        foreach ($this->buckets->findSeries($monitor, 'day', $from, $days) as $bucket) {
            $probes = $bucket->getProbeCount();
            $rows[] = [
                'start' => $bucket->getBucketStart(),
                'probes' => $probes,
                'uptime' => $probes > 0 ? round(100 * $bucket->getUpCount() / $probes, 1) : null,
                'avg_ms' => $bucket->getLatencyCount() > 0
                    ? (int) round($bucket->getLatencySumMs() / $bucket->getLatencyCount())
                    : null,
                'max_ms' => $bucket->getLatencyMaxMs(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{granularity: string, slot_seconds: int, slot_count: int, first: int, limit: int}
     */
    private static function window(string $range, int $now): array
    {
        $granularity = $range === self::RANGE_30D ? 'day' : 'hour';
        $slotSeconds = match ($range) {
            self::RANGE_7D => 21600,
            self::RANGE_30D => 86400,
            default => 3600,
        };
        $slotCount = match ($range) {
            self::RANGE_7D => 28,
            self::RANGE_30D => 30,
            default => 24,
        };

        $last = MetricBucketWriter::bucketStart($now, $granularity);
        $bucketsPerSlot = intdiv($slotSeconds, $granularity === 'hour' ? 3600 : 86400);

        return [
            'granularity' => $granularity,
            'slot_seconds' => $slotSeconds,
            'slot_count' => $slotCount,
            'first' => $last - ($slotCount - 1) * $slotSeconds,
            'limit' => $slotCount * $bucketsPerSlot,
        ];
    }

    /**
     * @param array{granularity: string, slot_seconds: int, slot_count: int, first: int, limit: int} $window
     * @param list<MetricBucket>                                                                      $buckets
     *
     * @return array{
     *     slot_seconds: int,
     *     slots: list<array{start: int, up: int, down: int, probes: int, uptime: float|null, avg_ms: int|null, max_ms: int|null, state: string}>,
     *     up: int,
     *     down: int,
     *     probes: int,
     *     uptime: float|null,
     *     avg_ms: int|null,
     *     max_ms: int|null
     * }
     */
    private static function buildSeries(array $window, array $buckets): array
    {
        $count = $window['slot_count'];
        $first = $window['first'];
        $slotSeconds = $window['slot_seconds'];

        $raw = [];
        for ($i = 0; $i < $count; ++$i) {
            $raw[] = [
                'start' => $first + $i * $slotSeconds,
                'up' => 0,
                'down' => 0,
                'probes' => 0,
                'latency_sum' => 0,
                'latency_count' => 0,
                'max_ms' => null,
            ];
        }

        foreach ($buckets as $bucket) {
            $index = intdiv($bucket->getBucketStart() - $first, $slotSeconds);
            if ($index < 0 || $index >= $count) {
                continue;
            }

            $raw[$index]['up'] += $bucket->getUpCount();
            $raw[$index]['down'] += $bucket->getDownCount();
            $raw[$index]['probes'] += $bucket->getProbeCount();
            $raw[$index]['latency_sum'] += $bucket->getLatencySumMs();
            $raw[$index]['latency_count'] += $bucket->getLatencyCount();

            $max = $bucket->getLatencyMaxMs();
            if ($max !== null && ($raw[$index]['max_ms'] === null || $max > $raw[$index]['max_ms'])) {
                $raw[$index]['max_ms'] = $max;
            }
        }

        $slots = [];
        $up = 0;
        $down = 0;
        $probes = 0;
        $latencySum = 0;
        $latencyCount = 0;
        $maxMs = null;

        foreach ($raw as $slot) {
            $up += $slot['up'];
            $down += $slot['down'];
            $probes += $slot['probes'];
            $latencySum += $slot['latency_sum'];
            $latencyCount += $slot['latency_count'];
            if ($slot['max_ms'] !== null && ($maxMs === null || $slot['max_ms'] > $maxMs)) {
                $maxMs = $slot['max_ms'];
            }

            $slots[] = [
                'start' => $slot['start'],
                'up' => $slot['up'],
                'down' => $slot['down'],
                'probes' => $slot['probes'],
                'uptime' => $slot['probes'] > 0 ? round(100 * $slot['up'] / $slot['probes'], 1) : null,
                'avg_ms' => $slot['latency_count'] > 0
                    ? (int) round($slot['latency_sum'] / $slot['latency_count'])
                    : null,
                'max_ms' => $slot['max_ms'],
                'state' => $slot['probes'] === 0 ? 'empty' : ($slot['down'] > 0 ? 'down' : 'up'),
            ];
        }

        return [
            'slot_seconds' => $slotSeconds,
            'slots' => $slots,
            'up' => $up,
            'down' => $down,
            'probes' => $probes,
            'uptime' => $probes > 0 ? round(100 * $up / $probes, 1) : null,
            'avg_ms' => $latencyCount > 0 ? (int) round($latencySum / $latencyCount) : null,
            'max_ms' => $maxMs,
        ];
    }
}
