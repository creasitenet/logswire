<?php

declare(strict_types=1);

namespace App\Service\Retention;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class RetentionService
{
    public const int DEFAULT_BATCH_SIZE = 2000;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly int $retentionDays,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
    }

    /**
     * Delete every row older than the window, in batches, until none is left.
     *
     * @return array{events_deleted: int, issues_deleted: int}
     */
    public function runOnce(): array
    {
        if ($this->retentionDays <= 0) {
            $this->logger->info('Retention cleanup skipped (LOGSWIRE_RETENTION_DAYS <= 0)');

            return ['events_deleted' => 0, 'issues_deleted' => 0];
        }

        $limit = max(1, $this->batchSize);
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $this->retentionDays))
            ->format('Y-m-d H:i:s');

        $eventsDeleted = $this->deleteInBatches(
            'SELECT e.id FROM events e WHERE e.received_at < ? ORDER BY e.id ASC LIMIT '.$limit,
            'DELETE FROM events WHERE id IN (%s)',
            [$cutoff],
            $limit,
        );

        $issuesDeleted = $this->deleteInBatches(
            'SELECT i.id FROM issues i WHERE i.last_seen_at < ? ORDER BY i.last_seen_at ASC LIMIT '.$limit,
            'DELETE FROM issues WHERE id IN (%s)',
            [$cutoff],
            $limit,
        );

        $this->logger->info('Retention cleanup finished', [
            'events_deleted' => $eventsDeleted,
            'issues_deleted' => $issuesDeleted,
            'batch_size' => $limit,
        ]);

        return [
            'events_deleted' => $eventsDeleted,
            'issues_deleted' => $issuesDeleted,
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function deleteInBatches(
        string $selectSql,
        string $deleteSqlTemplate,
        array $params,
        int $batchSize,
    ): int {
        $deleted = 0;

        do {
            $ids = $this->db->fetchFirstColumn($selectSql, $params);
            if ($ids === []) {
                break;
            }
            $placeholders = implode(',', array_fill(0, \count($ids), '?'));
            $deleted += $this->db->executeStatement(
                sprintf($deleteSqlTemplate, $placeholders),
                $ids,
            );
        } while (\count($ids) === $batchSize);

        return $deleted;
    }
}
