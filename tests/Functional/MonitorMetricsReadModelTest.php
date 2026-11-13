<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Monitor;
use App\Probe\MetricBucketWriter;
use App\Probe\ProbeOutcome;
use App\Service\Monitor\MonitorMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * End-to-end read model: probes written by MetricBucketWriter are turned into
 * the 24 h / 7 d / 30 d slots rendered on the detail page.
 */
final class MonitorMetricsReadModelTest extends KernelTestCase
{
    private static bool $schemaReady = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$schemaReady) {
            return;
        }

        self::ensureKernelShutdown();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        self::$schemaReady = true;
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test24hSeriesAggregatesProbesAndKeepsGapsEmpty(): void
    {
        $now = 1_700_000_000;
        $monitor = $this->monitor();

        $writer = $this->writer();
        $writer->record($monitor->getId(), new ProbeOutcome(true, 200, 100, null), $now);
        $writer->record($monitor->getId(), new ProbeOutcome(true, 200, 200, null), $now - 3 * 3600);
        $writer->record($monitor->getId(), new ProbeOutcome(false, null, null, 'timeout'), $now - 3 * 3600);

        $series = $this->metrics()->series($monitor, MonitorMetrics::RANGE_24H, $now);

        self::assertSame(3600, $series['slot_seconds']);
        self::assertCount(24, $series['slots']);
        self::assertSame(3, $series['probes']);
        self::assertSame(2, $series['up']);
        self::assertSame(1, $series['down']);
        self::assertSame(66.7, $series['uptime']);
        self::assertSame(150, $series['avg_ms']);
        self::assertSame(200, $series['max_ms']);

        $last = $series['slots'][23];
        self::assertSame(1, $last['probes']);
        self::assertSame(100.0, $last['uptime']);
        self::assertSame(100, $last['avg_ms']);
        self::assertSame('up', $last['state']);

        $earlier = $series['slots'][20];
        self::assertSame(2, $earlier['probes']);
        self::assertSame(50.0, $earlier['uptime']);
        self::assertSame(200, $earlier['avg_ms']);
        self::assertSame('down', $earlier['state']);

        $empty = $series['slots'][0];
        self::assertSame(0, $empty['probes']);
        self::assertNull($empty['uptime']);
        self::assertNull($empty['avg_ms']);
        self::assertSame('empty', $empty['state']);
    }

    public function test7dAnd30dWindowsUseTheirOwnGranularity(): void
    {
        $now = 1_700_000_000;
        $monitor = $this->monitor();

        $writer = $this->writer();
        $writer->record($monitor->getId(), new ProbeOutcome(true, 200, 100, null), $now);

        $week = $this->metrics()->series($monitor, MonitorMetrics::RANGE_7D, $now);
        self::assertSame(21_600, $week['slot_seconds']);
        self::assertCount(28, $week['slots']);
        self::assertSame(1, $week['probes']);
        self::assertSame(100.0, $week['slots'][27]['uptime']);

        $month = $this->metrics()->series($monitor, MonitorMetrics::RANGE_30D, $now);
        self::assertSame(86_400, $month['slot_seconds']);
        self::assertCount(30, $month['slots']);
        self::assertSame(1, $month['probes']);
        self::assertSame(1, $month['slots'][29]['probes']);
    }

    public function testDailyRollupExposesStoredDayBucket(): void
    {
        $now = 1_700_000_000;
        $monitor = $this->monitor();

        $this->writer()->record($monitor->getId(), new ProbeOutcome(true, 200, 120, null), $now);

        $rows = $this->metrics()->dailyRollup($monitor, $now);

        self::assertCount(1, $rows);
        self::assertSame(MetricBucketWriter::bucketStart($now, 'day'), $rows[0]['start']);
        self::assertSame(1, $rows[0]['probes']);
        self::assertSame(100.0, $rows[0]['uptime']);
        self::assertSame(120, $rows[0]['avg_ms']);
    }

    private function metrics(): MonitorMetrics
    {
        $metrics = self::getContainer()->get(MonitorMetrics::class);
        \assert($metrics instanceof MonitorMetrics);

        return $metrics;
    }

    private function writer(): MetricBucketWriter
    {
        $writer = self::getContainer()->get(MetricBucketWriter::class);
        \assert($writer instanceof MetricBucketWriter);

        return $writer;
    }

    private function monitor(): Monitor
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $monitor = Monitor::create(Uuid::v4()->toRfc4122(), 'https://example.com/health', 'api-'.Uuid::v4()->toRfc4122());
        $em->persist($monitor);
        $em->flush();

        return $monitor;
    }
}
