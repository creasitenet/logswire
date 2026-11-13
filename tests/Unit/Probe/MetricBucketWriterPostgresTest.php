<?php

declare(strict_types=1);

namespace App\Tests\Unit\Probe;

use App\Probe\MetricBucketWriter;
use App\Probe\ProbeOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Replays the bucket UPSERT on PostgreSQL: the same SQL must not raise the
 * "column reference is ambiguous" error that SQLite silently tolerates.
 *
 * Opt-in: run `composer test-pg` (or set LOGSWIRE_PG_DSN) against a scratch
 * database. The table is created in a throw-away schema, never in public.
 */
final class MetricBucketWriterPostgresTest extends TestCase
{
    private const string SCHEMA = 'logswire_upsert_test';

    private ?Connection $db = null;

    protected function setUp(): void
    {
        $dsn = $_SERVER['LOGSWIRE_PG_DSN'] ?? getenv('LOGSWIRE_PG_DSN');
        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set LOGSWIRE_PG_DSN (or run composer test-pg) to replay the UPSERT on PostgreSQL.');
        }

        // DBAL 4 no longer understands a bare "url" parameter: the DSN must be
        // parsed into driver/connection params first.
        $params = (new DsnParser([
            'postgresql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'pgsql' => 'pdo_pgsql',
        ]))->parse($dsn);

        $this->db = DriverManager::getConnection($params);
        $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        $this->db->executeStatement('CREATE SCHEMA '.self::SCHEMA);
        $this->db->executeStatement('SET search_path TO '.self::SCHEMA);
        $this->db->executeStatement('CREATE TABLE monitors (id UUID PRIMARY KEY)');
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE metric_buckets (
                monitor_id UUID NOT NULL,
                granularity VARCHAR(8) NOT NULL,
                bucket_start INTEGER NOT NULL,
                probe_count INTEGER NOT NULL DEFAULT 0,
                up_count INTEGER NOT NULL DEFAULT 0,
                down_count INTEGER NOT NULL DEFAULT 0,
                latency_sum_ms INTEGER NOT NULL DEFAULT 0,
                latency_count INTEGER NOT NULL DEFAULT 0,
                latency_max_ms INTEGER NULL,
                PRIMARY KEY (monitor_id, granularity, bucket_start),
                CONSTRAINT fk_metric_buckets_monitor FOREIGN KEY (monitor_id) REFERENCES monitors (id) ON DELETE CASCADE
            )
        SQL);
    }

    protected function tearDown(): void
    {
        $this->db?->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        $this->db = null;
    }

    public function testUpsertAccumulatesOnPostgres(): void
    {
        \assert($this->db instanceof Connection);
        $monitorId = Uuid::v4()->toRfc4122();
        $this->db->executeStatement('INSERT INTO monitors (id) VALUES (?)', [$monitorId]);

        $writer = new MetricBucketWriter($this->db);
        $writer->record($monitorId, new ProbeOutcome(true, 200, 120, null), 1_700_000_000);
        $writer->record($monitorId, new ProbeOutcome(false, null, null, 'timeout'), 1_700_000_060);
        $writer->record($monitorId, new ProbeOutcome(true, 200, 500, null), 1_700_000_120);

        foreach (['hour', 'day'] as $granularity) {
            $row = $this->db->fetchAssociative(
                'SELECT * FROM metric_buckets WHERE monitor_id = ? AND granularity = ?',
                [$monitorId, $granularity],
            );
            \assert(\is_array($row));

            self::assertSame(3, (int) $row['probe_count'], $granularity);
            self::assertSame(2, (int) $row['up_count'], $granularity);
            self::assertSame(1, (int) $row['down_count'], $granularity);
            self::assertSame(620, (int) $row['latency_sum_ms'], $granularity);
            self::assertSame(2, (int) $row['latency_count'], $granularity);
            self::assertSame(500, (int) $row['latency_max_ms'], $granularity);
        }
    }
}
