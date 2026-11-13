<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Event;
use App\Entity\Issue;
use App\Repository\EventRepository;
use App\Repository\IssueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class IssueService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueRepository $issues,
        private readonly EventRepository $events,
        private readonly StacktraceReader $stacktraces,
    ) {
    }

    /** @return list<Issue> */
    public function list(
        ?string $status = null,
        ?string $projectId = null,
        ?string $q = null,
        ?int $levelExact = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        int $limit = 50,
        int $offset = 0,
        ?string $environment = null,
        ?string $release = null,
        ?string $sort = null,
        string $sortDir = 'desc',
    ): array {
        $projectUuid = null;
        if ($projectId !== null && $projectId !== '') {
            $projectUuid = Uuid::fromString($projectId);
        }

        return $this->issues->findFiltered(
            $status,
            $projectUuid,
            $q,
            $levelExact,
            $since,
            $until,
            $environment,
            $release,
            $limit,
            $offset,
            $sort,
            $sortDir,
        );
    }

    public function count(
        ?string $status = null,
        ?string $projectId = null,
        ?string $q = null,
        ?int $levelExact = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        ?string $environment = null,
        ?string $release = null,
    ): int {
        $projectUuid = null;
        if ($projectId !== null && $projectId !== '') {
            $projectUuid = Uuid::fromString($projectId);
        }

        return $this->issues->countFiltered(
            $status,
            $projectUuid,
            $q,
            $levelExact,
            $since,
            $until,
            $environment,
            $release,
        );
    }

    public function get(Uuid $id): Issue
    {
        $issue = $this->issues->findOneById($id);
        if (!$issue instanceof Issue) {
            throw new NotFoundHttpException('Issue not found.');
        }

        return $issue;
    }

    public function setStatus(Issue $issue, string $status): Issue
    {
        if (!\in_array($status, [Issue::STATUS_UNRESOLVED, Issue::STATUS_RESOLVED], true)) {
            throw new BadRequestHttpException('Status must be unresolved or resolved.');
        }
        $issue->setStatus($status);
        $this->em->flush();

        return $issue;
    }

    /** @return list<\App\Entity\Event> */
    public function recentEvents(Issue $issue, int $limit = 20): array
    {
        return $this->events->findRecentForIssue($issue, $limit);
    }

    /** @return array<string, mixed> */
    public function serializeListItem(Issue $issue): array
    {
        $project = $this->resolveProject($issue);

        return [
            'id' => $issue->getId(),
            'title' => $issue->getTitle(),
            'status' => $issue->getStatus(),
            'level' => $issue->getLevel(),
            'level_label' => IssueLevel::label($issue->getLevel()),
            'count' => $issue->getCount(),
            'culprit' => $issue->getCulprit(),
            'fingerprint' => $issue->getFingerprint(),
            'first_seen_at' => $issue->getFirstSeenAt()->format(\DateTimeInterface::ATOM),
            'last_seen_at' => $issue->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            'project' => $project ? [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'slug' => $project->getSlug(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeDetail(Issue $issue): array
    {
        $events = $this->recentEvents($issue);
        $latest = $events[0] ?? null;
        $payload = $latest?->getPayload();
        $firstHash = $this->events->findFirstWithStackHash($issue)?->getStackHash();
        $latestHash = $latest?->getStackHash();

        return [
            ...$this->serializeListItem($issue),
            'exception_type' => $latest?->getExceptionType(),
            'exception_value' => $latest?->getExceptionValue(),
            'message' => $latest?->getMessage(),
            'platform' => $latest?->getPlatform(),
            'environment' => $latest?->getEnvironment(),
            'release' => $latest?->getReleaseTag(),
            'tags' => $this->decodeTags($latest?->getTagsJson()),
            'request_method' => $latest?->getRequestMethod(),
            'request_url' => $latest?->getRequestUrl(),
            'os_name' => $latest?->getOsName(),
            'browser_name' => $latest?->getBrowserName(),
            'server_name' => $latest?->getServerName(),
            'client_ip' => $latest?->getClientIp(),
            'user_agent' => $latest?->getUserAgent(),
            'stack_hash' => $latestHash,
            'stack_diverged' => $firstHash !== null && $latestHash !== null && $firstHash !== $latestHash,
            'stacktrace' => $this->stacktraces->frames($payload),
            'occurrences' => array_map($this->serializeOccurrenceSummary(...), $events),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeOccurrenceSummary(Event $event): array
    {
        return [
            'id' => $event->getId(),
            'received_at' => $event->getReceivedAt()->format(\DateTimeInterface::ATOM),
            'environment' => $event->getEnvironment(),
            'release' => $event->getReleaseTag(),
            'client_ip' => $event->getClientIp(),
            'user_agent' => $event->getUserAgent(),
            'browser_name' => $event->getBrowserName(),
            'request_url' => $event->getRequestUrl(),
        ];
    }

    private function resolveProject(Issue $issue): ?\App\Entity\Project
    {
        $project = $issue->getProject();
        if ($project === null) {
            return null;
        }

        try {
            $project->getName();
        } catch (EntityNotFoundException) {
            return null;
        }

        return $project;
    }

    /** @return array<string, string> */
    private function decodeTags(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (\is_string($k) && (\is_string($v) || is_numeric($v))) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }
}
