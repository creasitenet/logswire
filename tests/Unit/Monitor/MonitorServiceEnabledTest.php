<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitor;

use App\Entity\Monitor;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use App\Service\Monitor\MonitorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Pause/resume must schedule the first check of a resumed monitor immediately,
 * otherwise it sits in the Unknown ("Pending") state until the old slot.
 */
#[AllowMockObjectsWithoutExpectations]
final class MonitorServiceEnabledTest extends TestCase
{
    public function testResumeSchedulesAnImmediateCheck(): void
    {
        $service = new MonitorService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MonitorRepository::class),
            $this->createMock(IncidentRepository::class),
        );
        $monitor = Monitor::create(
            '0192f0a0-0000-7000-8000-000000000010',
            'https://example.com/health',
            'api',
            300,
            1_000,
        );

        $service->setEnabled($monitor, false);
        self::assertFalse($monitor->isEnabled());
        self::assertSame(Monitor::STATUS_UNKNOWN, $monitor->getStatus());

        $service->setEnabled($monitor, true);

        self::assertTrue($monitor->isEnabled());
        self::assertLessThanOrEqual(1, abs($monitor->getNextCheckAt() - time()));
    }

    public function testSavingAnAlreadyEnabledMonitorKeepsItsSchedule(): void
    {
        $service = new MonitorService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MonitorRepository::class),
            $this->createMock(IncidentRepository::class),
        );
        $monitor = Monitor::create(
            '0192f0a0-0000-7000-8000-000000000011',
            'https://example.com/health',
            'api',
            300,
            1_000,
        );
        $monitor->setNextCheckAt(2_000);

        $service->setEnabled($monitor, true);

        self::assertSame(2_000, $monitor->getNextCheckAt());
    }
}
