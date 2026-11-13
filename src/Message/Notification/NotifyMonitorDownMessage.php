<?php

declare(strict_types=1);

namespace App\Message\Notification;

final class NotifyMonitorDownMessage
{
    public function __construct(
        public readonly string $monitorId,
        public readonly ?string $error,
    ) {
    }
}
