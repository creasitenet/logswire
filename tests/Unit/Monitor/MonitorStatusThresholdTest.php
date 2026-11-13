<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitor;

use App\Entity\Monitor;
use PHPUnit\Framework\TestCase;

/**
 * A single failed cycle must not flip a monitor: DOWN is confirmed only after
 * Monitor::DOWN_AFTER_FAILURES consecutive failures.
 */
final class MonitorStatusThresholdTest extends TestCase
{
    public function testFirstFailureIsAbsorbed(): void
    {
        $monitor = $this->monitor();

        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_000);

        self::assertSame(Monitor::STATUS_UNKNOWN, $monitor->getStatus());
        self::assertNull($monitor->getStatusSince());
        self::assertSame(1, $monitor->getFailStreak());
        self::assertSame('timeout', $monitor->getLastError());
        self::assertSame(1_000, $monitor->getLastCheckedAt());
    }

    public function testSecondFailureConfirmsDown(): void
    {
        $monitor = $this->monitor();

        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_000);
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_060);

        self::assertSame(Monitor::STATUS_DOWN, $monitor->getStatus());
        self::assertSame(1_060, $monitor->getStatusSince());
        self::assertSame(2, $monitor->getFailStreak());
    }

    public function testDownIsNotRepublishedWhileFailing(): void
    {
        $monitor = $this->monitor();

        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_000);
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_060);
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_120);

        self::assertSame(Monitor::STATUS_DOWN, $monitor->getStatus());
        self::assertSame(1_060, $monitor->getStatusSince());
        self::assertSame(3, $monitor->getFailStreak());
    }

    public function testRecoveryResetsStreakAndPublishesUpAtOnce(): void
    {
        $monitor = $this->monitor();

        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_000);
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_060);
        $monitor->applyProbeResult(Monitor::STATUS_UP, 200, 42, null, 1_120);

        self::assertSame(Monitor::STATUS_UP, $monitor->getStatus());
        self::assertSame(1_120, $monitor->getStatusSince());
        self::assertSame(0, $monitor->getFailStreak());
        self::assertSame(200, $monitor->getLastStatusCode());
        self::assertSame(42, $monitor->getLastResponseMs());
    }

    public function testSingleFailureDoesNotFlipAnUpMonitor(): void
    {
        $monitor = $this->monitor();
        $monitor->applyProbeResult(Monitor::STATUS_UP, 200, 30, null, 1_000);

        $monitor->applyProbeResult(Monitor::STATUS_DOWN, 500, 30, 'HTTP 500', 1_060);

        self::assertSame(Monitor::STATUS_UP, $monitor->getStatus());
        self::assertSame(1_000, $monitor->getStatusSince());
        self::assertSame(1, $monitor->getFailStreak());
    }

    public function testResetProbeStateForgetsEverything(): void
    {
        $monitor = $this->monitor();
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_000);
        $monitor->applyProbeResult(Monitor::STATUS_DOWN, null, null, 'timeout', 1_060);

        $monitor->resetProbeState(1_200);

        self::assertSame(Monitor::STATUS_UNKNOWN, $monitor->getStatus());
        self::assertNull($monitor->getStatusSince());
        self::assertSame(0, $monitor->getFailStreak());
        self::assertSame(1_200, $monitor->getUpdatedAt());
    }

    private function monitor(): Monitor
    {
        return Monitor::create('0192f0a0-0000-7000-8000-000000000001', 'https://example.com/health', 'api');
    }
}
