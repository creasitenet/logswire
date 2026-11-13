<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * HTTP endpoint checked on a fixed interval by the probe loop.
 *
 * Timestamps are Unix seconds in UTC: they are compared and aggregated in SQL,
 * so storing integers keeps the probe path free of timezone conversions.
 */
#[ORM\Entity(repositoryClass: MonitorRepository::class)]
#[ORM\Table(name: 'monitors')]
#[ORM\Index(name: 'idx_monitors_due', columns: ['is_enabled', 'next_check_at'])]
final class Monitor
{
    public const string STATUS_UP = 'up';
    public const string STATUS_DOWN = 'down';
    public const string STATUS_UNKNOWN = 'unknown';

    /** Consecutive failed cycles required before a monitor is declared DOWN. */
    public const int DOWN_AFTER_FAILURES = 2;

    /** Floor for the probe interval, in seconds. */
    public const int MIN_INTERVAL_SEC = 60;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(name: 'interval_sec')]
    private int $intervalSec = 300;

    #[ORM\Column(name: 'is_enabled')]
    private bool $enabled = true;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_UNKNOWN;

    #[ORM\Column(name: 'status_since', nullable: true)]
    private ?int $statusSince = null;

    #[ORM\Column(name: 'fail_streak')]
    private int $failStreak = 0;

    #[ORM\Column(name: 'last_checked_at', nullable: true)]
    private ?int $lastCheckedAt = null;

    #[ORM\Column(name: 'last_status_code', nullable: true)]
    private ?int $lastStatusCode = null;

    #[ORM\Column(name: 'last_response_ms', nullable: true)]
    private ?int $lastResponseMs = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'next_check_at')]
    private int $nextCheckAt = 0;

    #[ORM\Column(name: 'created_at')]
    private int $createdAt = 0;

    #[ORM\Column(name: 'updated_at')]
    private int $updatedAt = 0;

    public static function create(string $id, string $url, string $name, int $intervalSec = 300, ?int $now = null): self
    {
        $now ??= time();

        $monitor = new self();
        $monitor->id = $id;
        $monitor->url = $url;
        $monitor->name = $name;
        $monitor->intervalSec = max(self::MIN_INTERVAL_SEC, $intervalSec);
        $monitor->nextCheckAt = $now;
        $monitor->createdAt = $now;
        $monitor->updatedAt = $now;

        return $monitor;
    }

    /**
     * @param string      $status     one of the STATUS_* constants
     * @param int|null    $responseMs null when the request never completed
     */
    public function applyProbeResult(string $status, ?int $statusCode, ?int $responseMs, ?string $error, int $now): void
    {
        $this->failStreak = $status === self::STATUS_UP ? 0 : $this->failStreak + 1;

        // A single failed cycle does not flip a monitor: transient blips are
        // absorbed, DOWN is only confirmed after repeated failures.
        if (($status === self::STATUS_UP || $this->failStreak >= self::DOWN_AFTER_FAILURES) && $status !== $this->status) {
            $this->status = $status;
            $this->statusSince = $now;
        }

        $this->lastCheckedAt = $now;
        $this->lastStatusCode = $statusCode;
        $this->lastResponseMs = $responseMs;
        $this->lastError = $error;
        $this->updatedAt = $now;
    }

    public function setNextCheckAt(int $nextCheckAt): void
    {
        $this->nextCheckAt = $nextCheckAt;
    }

    public function updateSettings(string $name, string $url, int $intervalSec, int $now): void
    {
        $this->name = $name;
        $this->url = $url;
        $this->intervalSec = max(self::MIN_INTERVAL_SEC, $intervalSec);
        $this->updatedAt = $now;
    }

    public function setEnabled(bool $enabled, int $now): void
    {
        $this->enabled = $enabled;
        $this->updatedAt = $now;
    }

    /** Forget the last probe state, e.g. when the monitor is paused. */
    public function resetProbeState(int $now): void
    {
        $this->status = self::STATUS_UNKNOWN;
        $this->statusSince = null;
        $this->failStreak = 0;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getIntervalSec(): int
    {
        return $this->intervalSec;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusSince(): ?int
    {
        return $this->statusSince;
    }

    public function getFailStreak(): int
    {
        return $this->failStreak;
    }

    public function getLastCheckedAt(): ?int
    {
        return $this->lastCheckedAt;
    }

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    public function getLastResponseMs(): ?int
    {
        return $this->lastResponseMs;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getNextCheckAt(): int
    {
        return $this->nextCheckAt;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }
}
