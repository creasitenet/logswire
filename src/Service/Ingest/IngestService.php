<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Event;
use App\Entity\Issue;
use App\Entity\Project;
use App\Repository\EventRepository;
use App\Repository\IssueRepository;
use App\Service\Issue\StacktraceReader;
use App\Service\Notification\NotificationQueue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sentry-compatible ingest: parse envelope / JSON, persist events, notify on new issues.
 */
final class IngestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueRepository $issues,
        private readonly EventRepository $events,
        private readonly StacktraceReader $stacktraces,
        private readonly NotificationQueue $notifications,
        private readonly PayloadScrubber $scrubber,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ingestEnvelope(Project $project, ParsedEnvelope $parsed, ?string $fingerprintOverride = null): IngestResult
    {
        $this->assertProjectActive($project);

        if ($parsed->events === []) {
            return new IngestResult([], $parsed->dropped);
        }

        return $this->persistPayloads($project, $parsed->events, $parsed->dropped, $fingerprintOverride);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function ingestPayloads(Project $project, array $payloads, ?string $fingerprintOverride = null): IngestResult
    {
        $this->assertProjectActive($project);

        if ($payloads === []) {
            throw new BadRequestHttpException('No events to ingest.');
        }

        return $this->persistPayloads($project, $payloads, 0, $fingerprintOverride);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function persistPayloads(
        Project $project,
        array $payloads,
        int $dropped,
        ?string $fingerprintOverride,
    ): IngestResult {
        /** @var list<array{event: Event, issue: Issue, isNewIssue: bool, reopened: bool}> $stored */
        $stored = [];

        $this->em->wrapInTransaction(function () use ($project, $payloads, $fingerprintOverride, &$stored): void {
            /** @var array<string, Issue> $issuesByFingerprint */
            $issuesByFingerprint = [];
            foreach ($payloads as $index => $payload) {
                $override = $index === 0 ? $fingerprintOverride : null;
                $stored[] = $this->persistOne($project, $payload, $override, $issuesByFingerprint);
            }
            $this->em->flush();

            // Only the latest occurrence per issue keeps its full payload.
            foreach ($stored as $row) {
                $this->events->clearPayloadBefore($row['event']);
            }
        });

        foreach ($stored as $row) {
            if ($row['isNewIssue']) {
                $this->notifications->queueNewIssue($project, $row['issue'], $row['event']);
            } elseif ($row['reopened']) {
                $this->notifications->queueReopenedIssue($project, $row['issue'], $row['event']);
            }
        }

        $events = array_map(static fn (array $row): Event => $row['event'], $stored);

        $newIssues = 0;
        $reopened = 0;
        foreach ($stored as $row) {
            if ($row['isNewIssue']) {
                ++$newIssues;
            } elseif ($row['reopened']) {
                ++$reopened;
            }
        }

        $this->logger->info('Ingested batch', [
            'project_id' => $project->getId(),
            'events' => \count($events),
            'dropped' => $dropped,
            'new_issues' => $newIssues,
            'reopened_issues' => $reopened,
        ]);

        return new IngestResult($events, $dropped);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, Issue> $issuesByFingerprint issues inserted earlier in the same batch
     *
     * @return array{event: Event, issue: Issue, isNewIssue: bool, reopened: bool}
     */
    private function persistOne(
        Project $project,
        array $payload,
        ?string $fingerprintOverride,
        array &$issuesByFingerprint,
    ): array {
        $mapped = SentryPayloadMapper::map($payload, $fingerprintOverride);
        $context = SentryPayloadMapper::mapContext($payload);

        [$issue, $isNew, $reopened] = $this->findOrCreateIssue(
            $project,
            $issuesByFingerprint,
            $mapped['fingerprint'],
            $mapped['title'],
            $mapped['level'],
            $mapped['culprit'],
        );

        $sdkName = $payload['sdk']['name'] ?? null;
        $sdkVersion = $payload['sdk']['version'] ?? null;
        $payloadJson = json_encode(
            $this->scrubber->scrub($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: null;

        $event = (new Event())
            ->setProject($project)
            ->setIssue($issue)
            ->setReceivedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setMessage($mapped['message'])
            ->setLevel($mapped['level'])
            ->setFingerprint($mapped['fingerprint'])
            ->setExceptionType($mapped['exceptionType'])
            ->setExceptionValue($mapped['exceptionValue'])
            ->setPlatform(isset($payload['platform']) ? (string) $payload['platform'] : null)
            ->setEnvironment(isset($payload['environment']) ? (string) $payload['environment'] : null)
            ->setReleaseTag(isset($payload['release']) ? (string) $payload['release'] : null)
            ->setTagsJson(isset($payload['tags']) ? json_encode($payload['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)
            ->setSdkName(\is_string($sdkName) ? $sdkName : null)
            ->setSdkVersion(\is_string($sdkVersion) ? $sdkVersion : null)
            ->setRequestMethod($context['requestMethod'])
            ->setRequestUrl($context['requestUrl'])
            ->setOsName($context['osName'])
            ->setBrowserName($context['browserName'])
            ->setServerName($context['serverName'])
            ->setClientIp($context['clientIp'])
            ->setUserAgent($context['userAgent'])
            ->setEventTimestamp($mapped['eventTimestamp'])
            ->setPayload($payloadJson)
            ->setStackHash($this->stacktraces->hash($payloadJson));

        $this->em->persist($event);

        $issue->setLastSeenAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $issue->setLastEnvironment($event->getEnvironment());
        $issue->setLastRelease($event->getReleaseTag());
        if ($mapped['level'] > $issue->getLevel()) {
            $issue->setLevel($mapped['level']);
        }
        if (!$isNew) {
            $issue->setCount($issue->getCount() + 1);
        }

        return ['event' => $event, 'issue' => $issue, 'isNewIssue' => $isNew, 'reopened' => $reopened];
    }

    /**
     * @param array<string, Issue> $issuesByFingerprint issues inserted earlier in the same batch
     *
     * @return array{0: Issue, 1: bool, 2: bool}
     */
    private function findOrCreateIssue(
        Project $project,
        array &$issuesByFingerprint,
        string $fingerprint,
        string $title,
        int $level,
        ?string $culprit,
    ): array {
        $existing = $issuesByFingerprint[$fingerprint] ?? $this->issues->findOneBy([
            'project' => $project,
            'fingerprint' => $fingerprint,
        ]);
        if ($existing instanceof Issue) {
            $issuesByFingerprint[$fingerprint] = $existing;
            $reopened = $existing->getStatus() === Issue::STATUS_RESOLVED;
            if ($reopened) {
                $existing->setStatus(Issue::STATUS_UNRESOLVED);
            }

            return [$existing, false, $reopened];
        }

        $issue = (new Issue())
            ->setProject($project)
            ->setFingerprint($fingerprint)
            ->setTitle($title)
            ->setLevel($level)
            ->setStatus(Issue::STATUS_UNRESOLVED);

        if ($culprit !== null) {
            $issue->setCulprit($culprit);
        }

        $this->em->persist($issue);
        $issuesByFingerprint[$fingerprint] = $issue;

        return [$issue, true, false];
    }

    private function assertProjectActive(Project $project): void
    {
        if (!$project->isActive()) {
            throw new HttpException(403, 'project_inactive');
        }
    }
}
