<?php

declare(strict_types=1);

namespace App\Message\Notification;

final class NotifyMonitorRecoveredMessage
{
    public function __construct(
        public readonly string $monitorId,
        public readonly ?int $downtimeSeconds,
    ) {
    }
}
