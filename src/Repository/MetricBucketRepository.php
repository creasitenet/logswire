<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MetricBucket;
use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetricBucket>
 */
final class MetricBucketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricBucket::class);
    }

    /**
     * @return list<MetricBucket>
     */
    public function findSeries(Monitor $monitor, string $granularity, int $since, int $limit): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.monitor = :monitor')
            ->andWhere('b.granularity = :granularity')
            ->andWhere('b.bucketStart >= :since')
            ->setParameter('monitor', $monitor)
            ->setParameter('granularity', $granularity)
            ->setParameter('since', $since)
            ->orderBy('b.bucketStart', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Hour buckets for several monitors at once (monitor list sparklines).
     *
     * @param list<string> $monitorIds
     *
     * @return list<MetricBucket>
     */
    public function findHourlySince(array $monitorIds, int $since): array
    {
        if ($monitorIds === []) {
            return [];
        }

        return $this->createQueryBuilder('b')
            ->andWhere('IDENTITY(b.monitor) IN (:ids)')
            ->andWhere('b.granularity = :granularity')
            ->andWhere('b.bucketStart >= :since')
            ->setParameter('ids', $monitorIds)
            ->setParameter('granularity', 'hour')
            ->setParameter('since', $since)
            ->orderBy('b.bucketStart', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
