<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Event;
use App\Entity\Issue;
use App\Entity\Project;
use App\Service\Issue\IssueLevel;
use Psr\Log\LoggerInterface;

/**
 * Alerts on the first occurrence of a new error/fatal issue, and optionally
 * when a resolved issue reopens.
 */
final class IssueAlerter
{
    private const ALERT_MIN_LEVEL = IssueLevel::ERROR;

    public function __construct(
        private readonly TelegramChannel $telegram,
        private readonly LoggerInterface $logger,
        private readonly bool $renotifyReopened = false,
    ) {
    }

    public function onNewIssue(Project $project, Issue $issue, Event $event): void
    {
        if ($event->getLevel() < self::ALERT_MIN_LEVEL) {
            return;
        }

        $this->send($project, $issue, $this->buildMessage($project, $issue, $event, '1st occurrence'));
    }

    public function onReopenedIssue(Project $project, Issue $issue, Event $event): void
    {
        if (!$this->renotifyReopened || $event->getLevel() < self::ALERT_MIN_LEVEL) {
            return;
        }

        $this->send($project, $issue, $this->buildMessage($project, $issue, $event, 'reopened'));
    }

    private function send(Project $project, Issue $issue, string $text): void
    {
        try {
            $this->telegram->notify($text);
        } catch (\Throwable $e) {
            $this->logger->warning('IssueAlerter: notify failed', [
                'project_id' => $project->getId(),
                'issue_id' => $issue->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildMessage(Project $project, Issue $issue, Event $event, string $state): string
    {
        return sprintf(
            "[errors] %s in %s\n*%s*\n`%s`\n_Issue %s — %s_",
            IssueLevel::label($event->getLevel()),
            TelegramChannel::escape($project->getName()),
            TelegramChannel::escape($issue->getTitle()),
            TelegramChannel::escape($issue->getCulprit() ?? '(no culprit)'),
            $issue->getId(),
            $state,
        );
    }
}
