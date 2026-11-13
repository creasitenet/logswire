<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Issue>
 */
final class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issue::class);
    }

    public function countFiltered(
        ?string $status = null,
        ?Uuid $projectId = null,
        ?string $q = null,
        ?int $levelExact = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        ?string $environment = null,
        ?string $release = null,
    ): int {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)');
        $this->applyListFilters(
            $qb,
            $status,
            $projectId,
            $q,
            $levelExact,
            $since,
            $until,
            $environment,
            $release,
        );

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Issue>
     */
    public function findFiltered(
        ?string $status = null,
        ?Uuid $projectId = null,
        ?string $q = null,
        ?int $levelExact = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        ?string $environment = null,
        ?string $release = null,
        int $limit = 50,
        int $offset = 0,
        ?string $sort = null,
        string $sortDir = 'desc',
    ): array {
        $qb = $this->createQueryBuilder('i')
            ->setMaxResults(max(1, min(100, $limit)))
            ->setFirstResult(max(0, $offset));
        $this->applyListFilters(
            $qb,
            $status,
            $projectId,
            $q,
            $levelExact,
            $since,
            $until,
            $environment,
            $release,
        );
        $this->applySort($qb, $sort, $sortDir);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $qb root alias must be `i`
     */
    private function applyListFilters(
        \Doctrine\ORM\QueryBuilder $qb,
        ?string $status,
        ?Uuid $projectId,
        ?string $q,
        ?int $levelExact,
        ?\DateTimeImmutable $since,
        ?\DateTimeImmutable $until,
        ?string $environment,
        ?string $release,
    ): void {
        if ($status !== null && $status !== '') {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }
        if ($projectId !== null) {
            $qb->andWhere('i.project = :projectId')->setParameter('projectId', $projectId->toRfc4122());
        }
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('LOWER(i.title) LIKE :q OR LOWER(COALESCE(i.culprit, \'\')) LIKE :q OR LOWER(i.fingerprint) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }
        if ($levelExact !== null) {
            $qb->andWhere('i.level = :levelExact')->setParameter('levelExact', $levelExact);
        }
        if ($since !== null) {
            $qb->andWhere('i.lastSeenAt >= :since')->setParameter('since', $since);
        }
        if ($until !== null) {
            $qb->andWhere('i.lastSeenAt <= :until')->setParameter('until', $until);
        }
        if ($environment !== null && trim($environment) !== '') {
            $qb->andWhere('LOWER(COALESCE(i.lastEnvironment, \'\')) = :environment')
                ->setParameter('environment', mb_strtolower(trim($environment)));
        }
        if ($release !== null && trim($release) !== '') {
            $qb->andWhere('LOWER(COALESCE(i.lastRelease, \'\')) = :releaseTag')
                ->setParameter('releaseTag', mb_strtolower(trim($release)));
        }
    }

    private function applySort(\Doctrine\ORM\QueryBuilder $qb, ?string $sort, string $sortDir): void
    {
        $direction = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
        $column = match ($sort) {
            'level' => 'i.level',
            'title' => 'i.title',
            'project' => 'p.name',
            'status' => 'i.status',
            'count' => 'i.count',
            'last_seen' => 'i.lastSeenAt',
            default => 'i.lastSeenAt',
        };

        if ($sort === 'project') {
            $qb->leftJoin('i.project', 'p');
        }

        $qb->orderBy($column, $direction);
        if ($sort !== null && $sort !== 'last_seen') {
            $qb->addOrderBy('i.lastSeenAt', 'DESC');
        }
    }

    public function countFirstSeenSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.firstSeenAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneById(Uuid $id): ?Issue
    {
        return $this->findOneBy(['id' => $id->toRfc4122()]);
    }

}
