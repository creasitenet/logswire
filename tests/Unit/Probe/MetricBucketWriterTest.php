<?php

declare(strict_types=1);

namespace App\Tests\Unit\Probe;

use App\Probe\MetricBucketWriter;
use App\Probe\ProbeOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * The writer aggregates every probe into an hour row and a day row with one
 * portable UPSERT. The same SQL runs on SQLite (tests) and PostgreSQL (prod).
 */
final class MetricBucketWriterTest extends TestCase
{
    private Connection $db;
    private MetricBucketWriter $writer;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE metric_buckets (
                monitor_id CHAR(36) NOT NULL,
                granularity VARCHAR(8) NOT NULL,
                bucket_start INTEGER NOT NULL,
                probe_count INTEGER NOT NULL DEFAULT 0,
                up_count INTEGER NOT NULL DEFAULT 0,
                down_count INTEGER NOT NULL DEFAULT 0,
                latency_sum_ms INTEGER NOT NULL DEFAULT 0,
                latency_count INTEGER NOT NULL DEFAULT 0,
                latency_max_ms INTEGER NULL,
                PRIMARY KEY (monitor_id, granularity, bucket_start)
            )
        SQL);
        $this->writer = new MetricBucketWriter($this->db);
    }

    public function testBucketStartAlignsOnHourAndDay(): void
    {
        self::assertSame(1_700_000_000 - 1_700_000_000 % 3600, MetricBucketWriter::bucketStart(1_700_000_000, 'hour'));
        self::assertSame(1_700_000_000 - 1_700_000_000 % 86400, MetricBucketWriter::bucketStart(1_700_000_000, 'day'));
        self::assertSame(0, MetricBucketWriter::bucketStart(0, 'hour'));
    }

    public function testRecordWritesHourAndDayRows(): void
    {
        $this->writer->record('monitor-1', new ProbeOutcome(true, 200, 120, null), 1_700_000_000);

        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM metric_buckets'));

        $hour = $this->row('monitor-1', 'hour');
        self::assertSame(1, (int) $hour['probe_count']);
        self::assertSame(1, (int) $hour['up_count']);
        self::assertSame(0, (int) $hour['down_count']);
        self::assertSame(120, (int) $hour['latency_sum_ms']);
        self::assertSame(1, (int) $hour['latency_count']);
        self::assertSame(120, (int) $hour['latency_max_ms']);

        $day = $this->row('monitor-1', 'day');
        self::assertSame(1, (int) $day['probe_count']);
        self::assertSame(120, (int) $day['latency_max_ms']);
    }

    public function testUpsertAccumulatesCountersInTheSameBucket(): void
    {
        $this->writer->record('monitor-1', new ProbeOutcome(true, 200, 120, null), 1_700_000_000);
        $this->writer->record('monitor-1', new ProbeOutcome(false, null, null, 'timeout'), 1_700_000_060);

        $hour = $this->row('monitor-1', 'hour');
        self::assertSame(2, (int) $hour['probe_count']);
        self::assertSame(1, (int) $hour['up_count']);
        self::assertSame(1, (int) $hour['down_count']);
        self::assertSame(120, (int) $hour['latency_sum_ms']);
        self::assertSame(1, (int) $hour['latency_count']);
        self::assertSame(120, (int) $hour['latency_max_ms']);
    }

    public function testUpsertKeepsTheHighestLatency(): void
    {
        $this->writer->record('monitor-1', new ProbeOutcome(true, 200, 120, null), 1_700_000_000);
        $this->writer->record('monitor-1', new ProbeOutcome(true, 200, 80, null), 1_700_000_060);
        $this->writer->record('monitor-1', new ProbeOutcome(true, 200, 500, null), 1_700_000_120);

        $hour = $this->row('monitor-1', 'hour');
        self::assertSame(3, (int) $hour['probe_count']);
        self::assertSame(700, (int) $hour['latency_sum_ms']);
        self::assertSame(3, (int) $hour['latency_count']);
        self::assertSame(500, (int) $hour['latency_max_ms']);
    }

    public function testRecordIsAtomic(): void
    {
        // The day upsert is forced to fail: the hour row written before it must
        // be rolled back with the enclosing transaction.
        $this->db->executeStatement(<<<'SQL'
            CREATE TRIGGER fail_day_insert BEFORE INSERT ON metric_buckets
            WHEN NEW.granularity = 'day'
            BEGIN
                SELECT RAISE(ABORT, 'day insert forbidden');
            END
        SQL);

        try {
            $this->writer->record('monitor-1', new ProbeOutcome(true, 200, 120, null), 1_700_000_000);
            self::fail('The failing day upsert should abort the whole record() call.');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM metric_buckets'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $monitorId, string $granularity): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM metric_buckets WHERE monitor_id = ? AND granularity = ?',
            [$monitorId, $granularity],
        );
        \assert(\is_array($row));

        return $row;
    }
}
