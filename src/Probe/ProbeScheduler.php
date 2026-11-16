<?php

declare(strict_types=1);

namespace App\Probe;

use App\Entity\Incident;
use App\Entity\Monitor;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\Notification\NotificationQueue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs one batch of due probes: probe, persist status, update next check,
 * aggregate buckets, maintain incidents and notify transitions.
 */
final class ProbeScheduler
{
    public function __construct(
        private readonly MonitorRepository $monitors,
        private readonly IncidentRepository $incidents,
        private readonly EntityManagerInterface $em,
        private readonly HttpProbe $probe,
        private readonly MetricBucketWriter $buckets,
        private readonly NotificationQueue $notifications,
        private readonly int $batchSize,
    ) {
    }

    /**
     * @return int number of monitors probed during this tick
     */
    public function tick(): int
    {
        $now = time();
        $processed = 0;

        foreach ($this->monitors->findDue($now, $this->batchSize) as $monitor) {
            $this->runOne($monitor, $now);
            ++$processed;
        }

        // The probe loop is a long-lived process. Without clearing, Doctrine's
        // identity map keeps the pre-pause copy of a monitor and ignores the
        // web process's pause/resume changes: the probe runs, but the status
        // column can stay "unknown" (Pending) forever.
        $this->em->clear();

        return $processed;
    }

    private function runOne(Monitor $monitor, int $now): void
    {
        $previous = $monitor->getStatus();
        $previousSince = $monitor->getStatusSince();

        $outcome = $this->probe->run($monitor->getUrl());
        $monitor->applyProbeResult(
            $outcome->status(),
            $outcome->statusCode,
            $outcome->responseMs,
            $outcome->error,
            $now,
        );
        $status = $monitor->getStatus();

        $interval = max(Monitor::MIN_INTERVAL_SEC, $monitor->getIntervalSec());
        $monitor->setNextCheckAt($now + ($status === Monitor::STATUS_DOWN ? max($interval * 2, 120) : $interval));

        $this->buckets->record($monitor->getId(), $outcome, $now);
        $this->syncIncident($monitor, $previous, $status, $outcome, $now);
        $this->em->flush();

        if ($status === Monitor::STATUS_UP && $previous === Monitor::STATUS_DOWN) {
            $this->notifications->queueMonitorRecovered(
                $monitor,
                $previousSince === null ? null : max(0, $now - $previousSince),
            );
        } elseif ($status === Monitor::STATUS_DOWN && $previous !== Monitor::STATUS_DOWN) {
            $this->notifications->queueMonitorDown($monitor, $outcome->error);
        }
    }

    private function syncIncident(Monitor $monitor, string $previous, string $status, ProbeOutcome $outcome, int $now): void
    {
        if ($status === Monitor::STATUS_UP) {
            if ($previous === Monitor::STATUS_DOWN) {
                $this->incidents->findOpenForMonitor($monitor)?->close($now);
            }

            return;
        }

        if ($status === Monitor::STATUS_DOWN) {
            if ($previous !== Monitor::STATUS_DOWN) {
                $this->em->persist(Incident::open($monitor, $outcome->error, $now));

                return;
            }

            $this->incidents->findOpenForMonitor($monitor)?->updateLastError($outcome->error);
        }
    }
}
