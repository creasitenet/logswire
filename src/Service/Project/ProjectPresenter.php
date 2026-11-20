<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Repository\IssueRepository;

/**
 * Twig view-models for the project list (issue counts per row).
 */
final class ProjectPresenter
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly IssueRepository $issues,
    ) {
    }

    /**
     * @param list<Project> $projects
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(array $projects): array
    {
        $ids = array_map(static fn (Project $p) => $p->getId(), $projects);
        $stats = $this->issues->unresolvedStatsForProjects($ids);

        $rows = [];
        foreach ($projects as $project) {
            $row = $this->projects->viewData($project);
            $issueStats = $stats[$project->getId()] ?? ['open' => 0, 'last_seen_at' => null];
            $row['issues_open'] = $issueStats['open'];
            $row['last_issue_at'] = $issueStats['last_seen_at'];
            $rows[] = $row;
        }

        return $rows;
    }
}