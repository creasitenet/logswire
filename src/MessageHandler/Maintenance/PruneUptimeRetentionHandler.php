<?php

declare(strict_types=1);

namespace App\MessageHandler\Maintenance;

use App\Message\Maintenance\PruneUptimeRetentionMessage;
use App\Probe\UptimeRetentionPruner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneUptimeRetentionHandler
{
    public function __construct(
        private readonly UptimeRetentionPruner $uptimeRetention,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneUptimeRetentionMessage $message): void
    {
        $stats = $this->uptimeRetention->prune(time());
        $this->logger->info('Uptime retention prune finished', $stats);
    }
}
