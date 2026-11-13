<?php

declare(strict_types=1);

namespace App\Probe;

use Doctrine\DBAL\Connection;

/**
 * Aggregates every probe into hour and day buckets with a portable UPSERT.
 *
 * The SQL avoids GREATEST/MAX(a, b) so it runs unchanged on SQLite and
 * PostgreSQL (MAX is an aggregate on Postgres). Existing-row columns are
 * prefixed with the table name: on PostgreSQL a bare name is ambiguous
 * between the target row and `excluded`.
 */
final class MetricBucketWriter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(string $monitorId, ProbeOutcome $outcome, int $checkedAt): void
    {
        $this->connection->transactional(function () use ($monitorId, $outcome, $checkedAt): void {
            foreach (['hour', 'day'] as $granularity) {
                $this->upsert($monitorId, $granularity, self::bucketStart($checkedAt, $granularity), $outcome);
            }
        });
    }

    public static function bucketStart(int $timestamp, string $granularity): int
    {
        if ($granularity === 'hour') {
            return $timestamp - ($timestamp % 3600);
        }

        return $timestamp - ($timestamp % 86400);
    }

    private function upsert(string $monitorId, string $granularity, int $bucketStart, ProbeOutcome $outcome): void
    {
        $latency = $outcome->up ? $outcome->responseMs : null;

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO metric_buckets (
                monitor_id, granularity, bucket_start,
                probe_count, up_count, down_count,
                latency_sum_ms, latency_count, latency_max_ms
            ) VALUES (
                :monitor_id, :granularity, :bucket_start,
                1, :up_count, :down_count,
                :latency_sum, :latency_count, :latency_max
            )
            ON CONFLICT (monitor_id, granularity, bucket_start) DO UPDATE SET
                probe_count = metric_buckets.probe_count + 1,
                up_count = metric_buckets.up_count + excluded.up_count,
                down_count = metric_buckets.down_count + excluded.down_count,
                latency_sum_ms = metric_buckets.latency_sum_ms + excluded.latency_sum_ms,
                latency_count = metric_buckets.latency_count + excluded.latency_count,
                latency_max_ms = CASE
                    WHEN excluded.latency_max_ms IS NULL THEN metric_buckets.latency_max_ms
                    WHEN metric_buckets.latency_max_ms IS NULL THEN excluded.latency_max_ms
                    WHEN excluded.latency_max_ms > metric_buckets.latency_max_ms THEN excluded.latency_max_ms
                    ELSE metric_buckets.latency_max_ms
                END
            SQL,
            [
                'monitor_id' => $monitorId,
                'granularity' => $granularity,
                'bucket_start' => $bucketStart,
                'up_count' => $outcome->up ? 1 : 0,
                'down_count' => $outcome->up ? 0 : 1,
                'latency_sum' => $latency ?? 0,
                'latency_count' => $latency !== null ? 1 : 0,
                'latency_max' => $latency,
            ],
        );
    }
}
