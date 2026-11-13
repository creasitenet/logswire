<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IncidentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Continuous DOWN period of a monitor: opened on the first failed probe and
 * closed by the next successful one. Duration is derived from the timestamps.
 */
#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'incidents')]
#[ORM\Index(name: 'idx_incidents_monitor_started', columns: ['monitor_id', 'started_at'])]
#[ORM\Index(name: 'idx_incidents_ended_at', columns: ['ended_at'])]
final class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER, options: ['autoincrement' => true])]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Column(name: 'started_at')]
    private int $startedAt;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    private ?int $endedAt = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public static function open(Monitor $monitor, ?string $error, int $now): self
    {
        $incident = new self();
        $incident->monitor = $monitor;
        $incident->startedAt = $now;
        $incident->lastError = $error;

        return $incident;
    }

    public function close(int $now): void
    {
        if ($this->endedAt === null) {
            $this->endedAt = $now;
        }
    }

    public function updateLastError(?string $error): void
    {
        $this->lastError = $error;
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function getDurationSeconds(?int $now = null): ?int
    {
        $end = $this->endedAt ?? $now;
        if ($end === null) {
            return null;
        }

        return max(0, $end - $this->startedAt);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function getStartedAt(): int
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?int
    {
        return $this->endedAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
