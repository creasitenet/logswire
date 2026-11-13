<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Touches worker.heartbeat so Compose can tell the consumer is alive, including
 * while a long maintenance handler runs (idle-only updates would look dead).
 */
final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/worker.heartbeat')]
        private readonly string $heartbeatFile,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'touch',
            WorkerRunningEvent::class => 'touch',
            WorkerMessageHandledEvent::class => 'touch',
        ];
    }

    public function touch(): void
    {
        @file_put_contents($this->heartbeatFile, (string) time());
    }
}
