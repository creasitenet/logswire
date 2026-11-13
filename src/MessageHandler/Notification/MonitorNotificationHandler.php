<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\Monitor;
use App\Message\Notification\NotifyMonitorDownMessage;
use App\Message\Notification\NotifyMonitorRecoveredMessage;
use App\Repository\MonitorRepository;
use App\Service\Telegram\MonitorAlerter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: NotifyMonitorDownMessage::class)]
#[AsMessageHandler(handles: NotifyMonitorRecoveredMessage::class)]
final class MonitorNotificationHandler
{
    public function __construct(
        private readonly MonitorRepository $monitors,
        private readonly MonitorAlerter $alerter,
    ) {
    }

    public function __invoke(NotifyMonitorDownMessage|NotifyMonitorRecoveredMessage $message): void
    {
        $monitor = $this->monitors->find($message->monitorId);
        if (!$monitor instanceof Monitor) {
            return;
        }

        if ($message instanceof NotifyMonitorDownMessage) {
            $this->alerter->onDown($monitor, $message->error);

            return;
        }

        $this->alerter->onRecovered($monitor, $message->downtimeSeconds);
    }
}
