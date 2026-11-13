<?php

declare(strict_types=1);

namespace App\MessageHandler\Maintenance;

use App\Message\Maintenance\PruneErrorRetentionMessage;
use App\Service\Retention\RetentionRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneErrorRetentionHandler
{
    public function __construct(
        private readonly RetentionRunner $retention,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneErrorRetentionMessage $message): void
    {
        $this->retention->maybeRun();
        $this->logger->info('Scheduled error retention prune finished.');
    }
}
