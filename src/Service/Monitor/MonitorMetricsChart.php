<?php

declare(strict_types=1);

namespace App\Service\Monitor;

/**
 * SVG geometry for uptime charts (bars + latency polyline).
 *
 * Two stacked drawings share the same horizontal scale: a short heartbeat band
 * (one bar per slot) and a taller latency plot with a graduated Y axis. The
 * left gutter keeps the Y labels outside the plot area.
 */
final class MonitorMetricsChart
{
    private const float PLOT_WIDTH = 900.0;
    private const float GUTTER = 44.0;
    private const float WIDTH = self::PLOT_WIDTH + self::GUTTER;

    private const float CHART_TOP = 8.0;
    private const float BASELINE = 186.0;
    private const float HEIGHT = self::BASELINE + 8.0;

    /** Height of the heartbeat band above the latency plot. */
    private const float UPTIME_TOP = 4.0;
    private const float UPTIME_BASELINE = 34.0;
    private const float UPTIME_HEIGHT = self::UPTIME_BASELINE + 4.0;

    /** Number of intervals the Y axis should be split into. */
    private const int TICK_INTERVALS = 3;

    /** Flat grey tick drawn for a slot without any probe (not a 0 % bar). */
    private const float CHART_EMPTY_TICK = 3.0;

    /**
     * SVG geometry for the uptime bars and the latency line: one latency path
     * per run of consecutive slots so a gap (missing bucket) is never bridged
     * by a straight line.
     *
     * @param list<array{uptime: float|null, avg_ms: int|null, state: string}> $slots
     *
     * @return array{
     *     width: float,
     *     gutter: float,
     *     height: float,
     *     baseline: float,
     *     uptime_height: float,
     *     uptime_baseline: float,
     *     max_ms: int,
     *     top_ms: int,
     *     ticks: list<array{y: float, label: int}>,
     *     bars: list<array{x: float, y: float, width: float, height: float, state: string}>,
     *     segments: list<string>,
     *     areas: list<string>,
     *     dots: list<array{x: float, y: float, down: bool, ms: int}>
     * }
     */
    public static function render(array $slots): array
    {
        $count = \count($slots);
        if ($count === 0) {
            return self::geometry(50, [], [], [], []);
        }

        $max = 50;
        foreach ($slots as $slot) {
            if ($slot['avg_ms'] !== null && $slot['avg_ms'] > $max) {
                $max = $slot['avg_ms'];
            }
        }

        $top = self::niceTop($max);
        $step = self::PLOT_WIDTH / $count;
        $barWidth = round($step * 0.8, 2);
        $barSpan = self::UPTIME_BASELINE - self::UPTIME_TOP;
        $bars = [];
        $segments = [];
        $areas = [];
        $dots = [];
        $run = [];

        $flush = static function () use (&$run, &$segments, &$areas): void {
            if (\count($run) < 2) {
                $run = [];

                return;
            }

            $line = array_map(static fn (array $point): string => $point[0].','.$point[1], $run);
            $segments[] = 'M '.implode(' L ', $line);
            $areas[] = 'M '.$line[0]
                .' L '.implode(' L ', $line)
                .' L '.$run[\count($run) - 1][0].','.self::BASELINE
                .' L '.$run[0][0].','.self::BASELINE.' Z';
            $run = [];
        };

        foreach ($slots as $index => $slot) {
            // A bar encodes the slot state (up / down / no data)
            // heartbeat: full band height so a single failed probe stays visible.
            $height = $slot['state'] === 'empty' ? self::CHART_EMPTY_TICK : $barSpan;
            $bars[] = [
                'x' => round(self::GUTTER + $index * $step + ($step - $barWidth) / 2, 2),
                'y' => round(self::UPTIME_BASELINE - $height, 2),
                'width' => $barWidth,
                'height' => $height,
                'state' => $slot['state'],
            ];

            if ($slot['avg_ms'] === null) {
                $flush();
                continue;
            }

            $x = round(self::GUTTER + ($index + 0.5) * $step, 2);
            $y = self::y($slot['avg_ms'], $top);
            $run[] = [$x, $y];
            $dots[] = [
                'x' => $x,
                'y' => $y,
                'down' => $slot['state'] === 'down',
                'ms' => $slot['avg_ms'],
            ];
        }

        $flush();

        return self::geometry($max, $bars, $segments, $areas, $dots, $top);
    }

    /**
     * @param list<array{x: float, y: float, width: float, height: float, state: string}> $bars
     * @param list<string> $segments
     * @param list<string> $areas
     * @param list<array{x: float, y: float, down: bool, ms: int}> $dots
     *
     * @return array{
     *     width: float,
     *     gutter: float,
     *     height: float,
     *     baseline: float,
     *     uptime_height: float,
     *     uptime_baseline: float,
     *     max_ms: int,
     *     top_ms: int,
     *     ticks: list<array{y: float, label: int}>,
     *     bars: list<array{x: float, y: float, width: float, height: float, state: string}>,
     *     segments: list<string>,
     *     areas: list<string>,
     *     dots: list<array{x: float, y: float, down: bool, ms: int}>
     * }
     */
    private static function geometry(int $max, array $bars, array $segments, array $areas, array $dots, ?int $top = null): array
    {
        $top ??= self::niceTop($max);
        $step = self::niceStep($top / self::TICK_INTERVALS);

        $ticks = [];
        for ($ms = 0; $ms <= $top; $ms += $step) {
            $ticks[] = ['y' => self::y($ms, $top), 'label' => $ms];
        }

        return [
            'width' => self::WIDTH,
            'gutter' => self::GUTTER,
            'height' => self::HEIGHT,
            'baseline' => self::BASELINE,
            'uptime_height' => self::UPTIME_HEIGHT,
            'uptime_baseline' => self::UPTIME_BASELINE,
            'max_ms' => $max,
            'top_ms' => $top,
            'ticks' => $ticks,
            'bars' => $bars,
            'segments' => $segments,
            'areas' => $areas,
            'dots' => $dots,
        ];
    }

    private static function y(int $ms, int $top): float
    {
        return round(self::BASELINE - ($ms / $top) * (self::BASELINE - self::CHART_TOP), 2);
    }

    /** Rounded-up ceiling so the peak never touches the top of the plot. */
    private static function niceTop(int $maxMs): int
    {
        $step = self::niceStep($maxMs / self::TICK_INTERVALS);

        return max(1, (int) (ceil($maxMs / $step) * $step));
    }

    /** Closest 1/2/5 × 10^n step, so tick labels stay readable. */
    private static function niceStep(float $raw): int
    {
        $magnitude = 10 ** floor(log10($raw));
        $fraction = $raw / $magnitude;
        $multiplier = match (true) {
            $fraction <= 1 => 1,
            $fraction <= 2 => 2,
            $fraction <= 5 => 5,
            default => 10,
        };

        return max(1, (int) ($multiplier * $magnitude));
    }
}
