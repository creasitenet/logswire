<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitor;

use App\Service\Monitor\MonitorMetrics;
use App\Service\Monitor\MonitorMetricsChart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SVG geometry of the detail charts. A slot without probes draws a flat tick
 * (never a 0 % bar) and a gap must never be bridged by a straight latency line.
 */
final class MonitorMetricsChartTest extends TestCase
{
    #[DataProvider('rangeProvider')]
    public function testNormalizeRangeFallsBackTo24h(?string $input, string $expected): void
    {
        self::assertSame($expected, MonitorMetrics::normalizeRange($input));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function rangeProvider(): iterable
    {
        yield 'null' => [null, MonitorMetrics::RANGE_24H];
        yield 'unknown' => ['1y', MonitorMetrics::RANGE_24H];
        yield 'empty' => ['', MonitorMetrics::RANGE_24H];
        yield '24h' => ['24h', MonitorMetrics::RANGE_24H];
        yield '7d' => ['7d', MonitorMetrics::RANGE_7D];
        yield '30d' => ['30d', MonitorMetrics::RANGE_30D];
    }

    public function testEmptyChartHasSafeDefaults(): void
    {
        $chart = MonitorMetricsChart::render([]);

        self::assertSame(944.0, $chart['width']);
        self::assertSame(44.0, $chart['gutter']);
        self::assertSame(194.0, $chart['height']);
        self::assertSame(186.0, $chart['baseline']);
        self::assertSame(38.0, $chart['uptime_height']);
        self::assertSame(34.0, $chart['uptime_baseline']);
        self::assertSame(50, $chart['max_ms']);
        self::assertSame(60, $chart['top_ms']);
        self::assertSame([0, 20, 40, 60], array_column($chart['ticks'], 'label'));
        self::assertSame([], $chart['bars']);
        self::assertSame([], $chart['segments']);
        self::assertSame([], $chart['areas']);
        self::assertSame([], $chart['dots']);
    }

    public function testSlotsWithoutProbesDrawAFlatTick(): void
    {
        $chart = MonitorMetricsChart::render([
            ['uptime' => null, 'avg_ms' => null, 'state' => 'empty'],
            ['uptime' => null, 'avg_ms' => null, 'state' => 'empty'],
        ]);

        self::assertSame(50, $chart['max_ms']);
        self::assertCount(2, $chart['bars']);

        foreach ($chart['bars'] as $bar) {
            self::assertSame(3.0, $bar['height']);
            self::assertSame(31.0, $bar['y']);
            self::assertSame('empty', $bar['state']);
        }

        self::assertSame([], $chart['dots']);
    }

    public function testBarsEncodeSlotStateOverTheFullHeartbeatBand(): void
    {
        $chart = MonitorMetricsChart::render([
            ['uptime' => 100.0, 'avg_ms' => 80, 'state' => 'up'],
            ['uptime' => 50.0, 'avg_ms' => 80, 'state' => 'down'],
        ]);

        // A single failed probe must stay visible: both states fill the band,
        // only the colour differs (as on the SaaS heartbeat).
        self::assertCount(2, $chart['bars']);
        foreach ($chart['bars'] as $bar) {
            self::assertSame(4.0, $bar['y']);
            self::assertSame(30.0, $bar['height']);
            self::assertSame(360.0, $bar['width']);
        }
        self::assertSame('up', $chart['bars'][0]['state']);
        self::assertSame('down', $chart['bars'][1]['state']);
    }

    public function testLatencyScaleIsRoundedUpToReadableTicks(): void
    {
        $chart = MonitorMetricsChart::render([
            ['uptime' => 100.0, 'avg_ms' => 100, 'state' => 'up'],
            ['uptime' => 100.0, 'avg_ms' => 140, 'state' => 'up'],
        ]);

        // Peak 140 ms is mapped on a 0–150 ms axis, never on the plot ceiling.
        self::assertSame(140, $chart['max_ms']);
        self::assertSame(150, $chart['top_ms']);
        self::assertSame([0, 50, 100, 150], array_column($chart['ticks'], 'label'));
        self::assertSame(186.0, $chart['ticks'][0]['y']);
        self::assertSame(8.0, $chart['ticks'][3]['y']);
        self::assertSame(19.87, $chart['dots'][1]['y']);
    }

    public function testGapSplitsLatencyLineAndMarksDownDots(): void
    {
        $chart = MonitorMetricsChart::render([
            ['uptime' => 100.0, 'avg_ms' => 100, 'state' => 'up'],
            ['uptime' => 100.0, 'avg_ms' => 120, 'state' => 'down'],
            ['uptime' => null, 'avg_ms' => null, 'state' => 'empty'],
            ['uptime' => 100.0, 'avg_ms' => 140, 'state' => 'up'],
        ]);

        self::assertSame(140, $chart['max_ms']);
        self::assertCount(4, $chart['bars']);

        // The first two slots form one run, the lone slot after the gap cannot
        // draw a line on its own: exactly one segment and one area.
        self::assertCount(1, $chart['segments']);
        self::assertCount(1, $chart['areas']);
        self::assertStringStartsWith('M ', $chart['segments'][0]);

        self::assertCount(3, $chart['dots']);
        self::assertFalse($chart['dots'][0]['down']);
        self::assertTrue($chart['dots'][1]['down']);
        self::assertFalse($chart['dots'][2]['down']);
        self::assertSame(19.87, $chart['dots'][2]['y']);
    }
}
