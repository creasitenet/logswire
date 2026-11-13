<?php

declare(strict_types=1);

namespace App\Service\Overview;

use App\Entity\Issue;
use App\Entity\Monitor;
use App\Repository\EventRepository;
use App\Repository\IncidentRepository;
use App\Repository\IssueRepository;
use App\Repository\MonitorRepository;
use App\Repository\ProjectRepository;
use App\Service\Database\DatabaseStatsService;
use App\Service\Issue\IssueLevel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Lightweight counts for the post-login overview (no payload scans).
 */
final class OverviewService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly MonitorRepository $monitors,
        private readonly IssueRepository $issues,
        private readonly IncidentRepository $incidents,
        private readonly EventRepository $events,
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{
     *     projects: int,
     *     monitors: int,
     *     monitors_down: int,
     *     issues_open: int,
     *     issues_new_24h: int,
     *     events_24h: int,
     *     incidents_open: int,
     *     database_label: string,
     *     recent_issues: list<array{
     *         id: string,
     *         title: string,
     *         level_label: string,
     *         count: int,
     *         last_seen_at: string,
     *         project_id: ?string,
     *         project_name: ?string
     *     }>,
     *     down_monitors: list<array{id: string, name: string, url: string}>
     * }
     */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $now->modify('-24 hours');

        $recent = $this->issues->findFiltered(
            Issue::STATUS_UNRESOLVED,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            8,
            0,
            'last_seen',
            'desc',
        );

        $recentRows = [];
        foreach ($recent as $issue) {
            $project = $issue->getProject();
            $recentRows[] = [
                'id' => $issue->getId(),
                'title' => $issue->getTitle() !== '' ? $issue->getTitle() : $issue->getFingerprint(),
                'level_label' => IssueLevel::label($issue->getLevel()),
                'count' => $issue->getCount(),
                'last_seen_at' => $issue->getLastSeenAt()->format(\DateTimeInterface::ATOM),
                'project_id' => $project?->getId(),
                'project_name' => $project?->getName(),
            ];
        }

        $downMonitors = [];
        foreach ($this->monitors->findDownEnabled(5) as $monitor) {
            $downMonitors[] = [
                'id' => $monitor->getId(),
                'name' => $monitor->getName(),
                'url' => $monitor->getUrl(),
            ];
        }

        return [
            'projects' => $this->projects->count([]),
            'monitors' => $this->monitors->count([]),
            'monitors_down' => $this->monitors->count(['status' => Monitor::STATUS_DOWN, 'enabled' => true]),
            'issues_open' => $this->issues->countFiltered(Issue::STATUS_UNRESOLVED),
            'issues_new_24h' => $this->issues->countFirstSeenSince($since),
            'events_24h' => $this->events->countReceivedSince($since),
            'incidents_open' => $this->incidents->countOpen(),
            'database_label' => $this->databaseSizeLabel(),
            'recent_issues' => $recentRows,
            'down_monitors' => $downMonitors,
        ];
    }

    private function databaseSizeLabel(): string
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $bytes = (int) $this->db->fetchOne('SELECT pg_database_size(current_database())');

            return DatabaseStatsService::formatBytes($bytes);
        }

        $path = $this->db->getParams()['path'] ?? null;
        if (!\is_string($path) || $path === '' || $path === ':memory:' || !is_file($path)) {
            return '—';
        }

        return DatabaseStatsService::formatBytes((int) filesize($path));
    }
}
