<?php

declare(strict_types=1);

namespace App\Tests\Unit\Probe;

use App\Entity\Incident;
use App\Entity\Monitor;
use App\Probe\HttpProbe;
use App\Probe\MetricBucketWriter;
use App\Probe\ProbeOutcome;
use App\Probe\ProbeScheduler;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\Notification\NotificationQueue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * One tick = one probe cycle. Alerts, incidents and the backoff must follow the
 * confirmed status (after the DOWN threshold), not the raw probe outcome.
 */
#[AllowMockObjectsWithoutExpectations]
final class ProbeSchedulerTest extends TestCase
{
    private MonitorRepository&MockObject $monitors;
    private IncidentRepository&MockObject $incidents;
    private EntityManagerInterface&MockObject $em;
    private HttpProbe&MockObject $probe;
    private MetricBucketWriter&MockObject $buckets;
    private NotificationQueue&MockObject $notifications;
    private ProbeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->monitors = $this->createMock(MonitorRepository::class);
        $this->incidents = $this->createMock(IncidentRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->probe = $this->createMock(HttpProbe::class);
        $this->buckets = $this->createMock(MetricBucketWriter::class);
        $this->notifications = $this->createMock(NotificationQueue::class);

        $this->scheduler = new ProbeScheduler(
            $this->monitors,
            $this->incidents,
            $this->em,
            $this->probe,
            $this->buckets,
            $this->notifications,
            10,
        );
    }

    public function testSingleFailureOnlyRecordsTheBucket(): void
    {
        $monitor = $this->monitor();
        $this->schedule($monitor);
        $this->probe->method('run')->willReturn(new ProbeOutcome(false, null, null, 'timeout'));

        $this->em->expects(self::never())->method('persist');
        $this->notifications->expects(self::never())->method('queueMonitorDown');
        $this->buckets->expects(self::once())->method('record');

        self::assertSame(1, $this->scheduler->tick());
        self::assertSame(Monitor::STATUS_UNKNOWN, $monitor->getStatus());
    }

    public function testSecondFailureConfirmsDownOpensIncidentAndAlerts(): void
    {
        $monitor = $this->monitor();
        $this->schedule($monitor);
        $this->probe->method('run')->willReturn(new ProbeOutcome(false, 500, 12, 'HTTP 500'));
        $this->incidents->method('findOpenForMonitor')->willReturn(null);

        $this->em->expects(self::exactly(1))->method('persist')->with(self::isInstanceOf(Incident::class));
        $this->notifications->expects(self::once())->method('queueMonitorDown')->with($monitor, 'HTTP 500');

        $before = time();
        $this->scheduler->tick();
        self::assertSame(Monitor::STATUS_UNKNOWN, $monitor->getStatus());

        $this->scheduler->tick();
        $after = time();

        self::assertSame(Monitor::STATUS_DOWN, $monitor->getStatus());
        self::assertGreaterThanOrEqual($before + 600, $monitor->getNextCheckAt());
        self::assertLessThanOrEqual($after + 600, $monitor->getNextCheckAt());
    }

    public function testRecoveryClosesTheIncidentAndAlertsOnce(): void
    {
        $monitor = $this->monitor();
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', time() - 300);
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', time() - 240);
        $incident = Incident::open($monitor, 'timeout', time() - 240);

        $this->schedule($monitor);
        $this->probe->method('run')->willReturn(new ProbeOutcome(true, 200, 33, null));
        $this->incidents->method('findOpenForMonitor')->willReturn($incident);

        $this->notifications->expects(self::once())->method('queueMonitorRecovered')->with($monitor, self::anything());
        $this->notifications->expects(self::never())->method('queueMonitorDown');

        $this->scheduler->tick();

        self::assertSame(Monitor::STATUS_UP, $monitor->getStatus());
        self::assertFalse($incident->isOpen());
        self::assertNotNull($incident->getEndedAt());
    }

    public function testUpMonitorStaysUpWithoutIncident(): void
    {
        $monitor = $this->monitor();
        $monitor->applyProbeResult(Monitor::STATUS_UP, 200, 20, null, time() - 120);

        $this->schedule($monitor);
        $this->probe->method('run')->willReturn(new ProbeOutcome(true, 200, 25, null));

        $this->em->expects(self::never())->method('persist');
        $this->notifications->expects(self::never())->method('queueMonitorRecovered');

        $before = time();
        $this->scheduler->tick();

        self::assertSame(Monitor::STATUS_UP, $monitor->getStatus());
        self::assertGreaterThanOrEqual($before + 300, $monitor->getNextCheckAt());
    }

    public function testTickClearsTheEntityManagerForTheNextCycle(): void
    {
        $this->schedule($this->monitor());
        $this->probe->method('run')->willReturn(new ProbeOutcome(true, 200, 10, null));
        $this->em->expects(self::once())->method('clear');

        self::assertSame(1, $this->scheduler->tick());
    }

    /**
     * @param array<Monitor> $monitors
     */
    private function schedule(Monitor ...$monitors): void
    {
        $this->monitors->method('findDue')->willReturn($monitors);
    }

    private function monitor(): Monitor
    {
        return Monitor::create('0192f0a0-0000-7000-8000-000000000002', 'https://example.com/health', 'api', 300);
    }
}
