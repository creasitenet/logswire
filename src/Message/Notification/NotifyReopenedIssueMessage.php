<?php

declare(strict_types=1);

namespace App\Message\Notification;

final class NotifyReopenedIssueMessage
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $issueId,
        public readonly string $eventId,
    ) {
    }
}
