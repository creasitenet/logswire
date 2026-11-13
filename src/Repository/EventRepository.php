<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Issue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
final class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    public function countReceivedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.receivedAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Event> */
    public function findRecentForIssue(Issue $issue, int $limit = 20): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.issue = :issue')
            ->setParameter('issue', $issue)
            // receivedAt has second precision: id is the stable tie-breaker, and the
            // highest id is also the occurrence whose full payload was kept.
            ->orderBy('e.receivedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(max(1, min(50, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function findFirstWithStackHash(Issue $issue): ?Event
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.issue = :issue')
            ->andWhere('e.stackHash IS NOT NULL')
            ->orderBy('e.id', 'ASC')
            ->setParameter('issue', $issue)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Only the newest occurrence of each distinct stacktrace keeps its full
     * payload. Older rows keep their stack hash and metadata, and a diverging
     * stack stays inspectable instead of being dropped with its duplicates.
     */
    public function clearPayloadBefore(Event $event): void
    {
        $issue = $event->getIssue();
        if ($issue === null || $event->getId() === null) {
            return;
        }

        $query = $this->getEntityManager()->createQueryBuilder()
            ->update(Event::class, 'e')
            ->set('e.payload', 'NULL')
            ->andWhere('e.issue = :issue')
            ->andWhere('e.id < :id')
            ->andWhere('e.payload IS NOT NULL')
            ->setParameter('issue', $issue)
            ->setParameter('id', (int) $event->getId());

        $hash = $event->getStackHash();
        if ($hash === null) {
            $query->andWhere('e.stackHash IS NULL');
        } else {
            $query->andWhere('e.stackHash = :hash')->setParameter('hash', $hash);
        }

        $query->getQuery()->execute();
    }
}
