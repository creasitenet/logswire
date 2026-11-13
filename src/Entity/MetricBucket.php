<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MetricBucketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Probe counters aggregated per hour or per day (UTC-aligned buckets).
 *
 * Rows are written by MetricBucketWriter with a portable UPSERT, then read by
 * MonitorMetrics to draw the 24 h / 7 d / 30 d charts and uptime percentages.
 */
#[ORM\Entity(repositoryClass: MetricBucketRepository::class)]
#[ORM\Table(name: 'metric_buckets')]
#[ORM\Index(name: 'idx_metric_buckets_granularity_start', columns: ['granularity', 'bucket_start'])]
final class MetricBucket
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Monitor $monitor;

    #[ORM\Id]
    #[ORM\Column(length: 8)]
    private string $granularity;

    #[ORM\Id]
    #[ORM\Column(name: 'bucket_start')]
    private int $bucketStart;

    #[ORM\Column(name: 'probe_count')]
    private int $probeCount = 0;

    #[ORM\Column(name: 'up_count')]
    private int $upCount = 0;

    #[ORM\Column(name: 'down_count')]
    private int $downCount = 0;

    #[ORM\Column(name: 'latency_sum_ms')]
    private int $latencySumMs = 0;

    #[ORM\Column(name: 'latency_count')]
    private int $latencyCount = 0;

    #[ORM\Column(name: 'latency_max_ms', type: Types::INTEGER, nullable: true)]
    private ?int $latencyMaxMs = null;

    public function getMonitor(): Monitor
    {
        return $this->monitor;
    }

    public function getBucketStart(): int
    {
        return $this->bucketStart;
    }

    public function getProbeCount(): int
    {
        return $this->probeCount;
    }

    public function getUpCount(): int
    {
        return $this->upCount;
    }

    public function getDownCount(): int
    {
        return $this->downCount;
    }

    public function getLatencySumMs(): int
    {
        return $this->latencySumMs;
    }

    public function getLatencyCount(): int
    {
        return $this->latencyCount;
    }

    public function getLatencyMaxMs(): ?int
    {
        return $this->latencyMaxMs;
    }
}
