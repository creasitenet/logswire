<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Monitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Monitor>
 */
final class MonitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Monitor::class);
    }

    /**
     * @return list<Monitor>
     */
    public function findAllForDashboard(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Monitor>
     */
    public function findDue(int $now, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.enabled = true')
            ->andWhere('m.nextCheckAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('m.nextCheckAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Monitor>
     */
    public function findDownEnabled(int $limit = 5): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.enabled = true')
            ->andWhere('m.status = :down')
            ->setParameter('down', Monitor::STATUS_DOWN)
            ->orderBy('m.name', 'ASC')
            ->setMaxResults(max(1, min(20, $limit)))
            ->getQuery()
            ->getResult();
    }
}
