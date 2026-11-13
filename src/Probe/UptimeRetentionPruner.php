<?php

declare(strict_types=1);

namespace App\Probe;

use Doctrine\DBAL\Connection;

/**
 * Prunes old uptime data: hourly buckets past the short window, daily buckets
 * and closed incidents past the long window.
 */
final class UptimeRetentionPruner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $retentionDays,
        private readonly int $hourRetentionDays,
    ) {
    }

    /**
     * @return array{hour_buckets: int, day_buckets: int, incidents: int}
     */
    public function prune(int $now): array
    {
        $hourBefore = $now - max(1, $this->hourRetentionDays) * 86400;
        $dayBefore = $now - max(1, $this->retentionDays) * 86400;

        $hour = (int) $this->connection->executeStatement(
            'DELETE FROM metric_buckets WHERE granularity = :g AND bucket_start < :t',
            ['g' => 'hour', 't' => $hourBefore],
        );
        $day = (int) $this->connection->executeStatement(
            'DELETE FROM metric_buckets WHERE granularity = :g AND bucket_start < :t',
            ['g' => 'day', 't' => $dayBefore],
        );
        $incidents = (int) $this->connection->executeStatement(
            'DELETE FROM incidents WHERE ended_at IS NOT NULL AND ended_at < :t',
            ['t' => $dayBefore],
        );

        return ['hour_buckets' => $hour, 'day_buckets' => $day, 'incidents' => $incidents];
    }
}
