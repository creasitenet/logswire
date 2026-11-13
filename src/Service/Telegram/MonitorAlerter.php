<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Monitor;
use App\Service\Monitor\MonitorService;
use Psr\Log\LoggerInterface;

/**
 * Sends uptime alerts on status transitions only ([uptime] prefix).
 */
final class MonitorAlerter
{
    public function __construct(
        private readonly TelegramChannel $telegram,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onDown(Monitor $monitor, ?string $error): void
    {
        $this->notify(\sprintf(
            "[uptime] DOWN — %s\n%s\n_%s_",
            TelegramChannel::escape($monitor->getName()),
            TelegramChannel::escape($monitor->getUrl()),
            TelegramChannel::escape($error ?? 'probe failed'),
        ), $monitor, 'down');
    }

    public function onRecovered(Monitor $monitor, ?int $downtimeSeconds): void
    {
        $this->notify(\sprintf(
            "[uptime] RECOVERED — %s\n%s\n_Downtime: %s_",
            TelegramChannel::escape($monitor->getName()),
            TelegramChannel::escape($monitor->getUrl()),
            $downtimeSeconds === null ? 'unknown' : MonitorService::formatDuration($downtimeSeconds),
        ), $monitor, 'recovered');
    }

    private function notify(string $text, Monitor $monitor, string $kind): void
    {
        try {
            $this->telegram->notify($text);
        } catch (\Throwable $e) {
            $this->logger->warning('MonitorAlerter: notify failed', [
                'monitor_id' => $monitor->getId(),
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
