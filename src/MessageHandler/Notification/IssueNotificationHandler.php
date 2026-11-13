<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\Event;
use App\Entity\Issue;
use App\Entity\Project;
use App\Message\Notification\NotifyNewIssueMessage;
use App\Message\Notification\NotifyReopenedIssueMessage;
use App\Repository\EventRepository;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Service\Telegram\IssueAlerter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: NotifyNewIssueMessage::class)]
#[AsMessageHandler(handles: NotifyReopenedIssueMessage::class)]
final class IssueNotificationHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly IssueRepository $issues,
        private readonly EventRepository $events,
        private readonly IssueAlerter $alerter,
    ) {
    }

    public function __invoke(NotifyNewIssueMessage|NotifyReopenedIssueMessage $message): void
    {
        $context = $this->load($message->projectId, $message->issueId, $message->eventId);
        if ($context === null) {
            return;
        }

        [$project, $issue, $event] = $context;

        if ($message instanceof NotifyNewIssueMessage) {
            $this->alerter->onNewIssue($project, $issue, $event);

            return;
        }

        $this->alerter->onReopenedIssue($project, $issue, $event);
    }

    /**
     * @return array{0: Project, 1: Issue, 2: Event}|null
     */
    private function load(string $projectId, string $issueId, string $eventId): ?array
    {
        $project = $this->projects->find($projectId);
        $issue = $this->issues->find($issueId);
        $event = $this->events->find($eventId);
        if (!$project instanceof Project || !$issue instanceof Issue || !$event instanceof Event) {
            return null;
        }

        return [$project, $issue, $event];
    }
}
