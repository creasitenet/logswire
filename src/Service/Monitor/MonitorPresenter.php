<?php

declare(strict_types=1);

namespace App\Service\Monitor;

use App\Entity\Monitor;
use App\Repository\IncidentRepository;

/**
 * Twig view-models for monitor list and detail (metrics + incidents + chart).
 */
final class MonitorPresenter
{
    public function __construct(
        private readonly MonitorMetrics $metrics,
        private readonly IncidentRepository $incidents,
    ) {
    }

    /**
     * @param list<Monitor> $monitors
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(array $monitors, ?int $now = null): array
    {
        $now ??= time();
        $sparklines = $this->metrics->sparklines($monitors, $now);

        $rows = [];
        foreach ($monitors as $monitor) {
            $series = $sparklines[$monitor->getId()] ?? null;
            $rows[] = MonitorService::viewData($monitor) + [
                'slots' => $series['slots'] ?? [],
                'uptime_24h' => $series['uptime'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Monitor $monitor, string $range, ?int $now = null): array
    {
        $now ??= time();
        $range = MonitorMetrics::normalizeRange($range);
        $last24h = $this->metrics->series($monitor, MonitorMetrics::RANGE_24H, $now);
        $series = $range === MonitorMetrics::RANGE_24H
            ? $last24h
            : $this->metrics->series($monitor, $range, $now);
        $last30d = $range === MonitorMetrics::RANGE_30D
            ? $series
            : $this->metrics->series($monitor, MonitorMetrics::RANGE_30D, $now);

        return [
            'monitor' => MonitorService::viewData($monitor),
            'range' => $range,
            'ranges' => [
                MonitorMetrics::RANGE_24H => '24 h',
                MonitorMetrics::RANGE_7D => '7 days',
                MonitorMetrics::RANGE_30D => '30 days',
            ],
            'series' => $series,
            'last24h' => $last24h,
            'last30d' => $last30d,
            'chart' => MonitorMetricsChart::render($series['slots']),
            'days' => $this->metrics->dailyRollup($monitor, $now),
            'incidents' => $this->formatIncidents($monitor, $now),
        ];
    }

    /**
     * @return list<array{id: int, started_at: int, ended_at: ?int, duration: string, error: ?string}>
     */
    private function formatIncidents(Monitor $monitor, int $now): array
    {
        $rows = [];
        foreach ($this->incidents->findRecentForMonitor($monitor, 20) as $incident) {
            $seconds = $incident->getDurationSeconds($now);
            $rows[] = [
                'id' => $incident->getId(),
                'started_at' => $incident->getStartedAt(),
                'ended_at' => $incident->getEndedAt(),
                'duration' => $seconds === null ? '—' : MonitorService::formatDuration($seconds),
                'error' => $incident->getLastError(),
            ];
        }

        return $rows;
    }
}
