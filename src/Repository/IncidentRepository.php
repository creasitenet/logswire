<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Incident;
use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Incident>
 */
final class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    public function findOpenForMonitor(Monitor $monitor): ?Incident
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.monitor = :monitor')
            ->andWhere('i.endedAt IS NULL')
            ->setParameter('monitor', $monitor)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Incident>
     */
    public function findRecentForMonitor(Monitor $monitor, int $limit = 20): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.monitor = :monitor')
            ->setParameter('monitor', $monitor)
            ->orderBy('i.startedAt', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.endedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
