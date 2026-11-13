<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Event;
use App\Entity\Issue;
use App\Entity\Monitor;
use App\Entity\Project;
use App\Message\Notification\NotifyMonitorDownMessage;
use App\Message\Notification\NotifyMonitorRecoveredMessage;
use App\Message\Notification\NotifyNewIssueMessage;
use App\Message\Notification\NotifyReopenedIssueMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Enqueues outbound alerts so ingest and probe ticks stay fast.
 */
final class NotificationQueue
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function queueNewIssue(Project $project, Issue $issue, Event $event): void
    {
        $this->bus->dispatch(new NotifyNewIssueMessage(
            $project->getId(),
            $issue->getId(),
            $event->getId(),
        ));
    }

    public function queueReopenedIssue(Project $project, Issue $issue, Event $event): void
    {
        $this->bus->dispatch(new NotifyReopenedIssueMessage(
            $project->getId(),
            $issue->getId(),
            $event->getId(),
        ));
    }

    public function queueMonitorDown(Monitor $monitor, ?string $error): void
    {
        $this->bus->dispatch(new NotifyMonitorDownMessage($monitor->getId(), $error));
    }

    public function queueMonitorRecovered(Monitor $monitor, ?int $downtimeSeconds): void
    {
        $this->bus->dispatch(new NotifyMonitorRecoveredMessage($monitor->getId(), $downtimeSeconds));
    }
}
