<?php

declare(strict_types=1);

namespace App\Service\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Capacity hints for Settings (heavy scans). Headline counts live on {@see \App\Service\Overview\OverviewService}.
 */
final class DatabaseStatsService
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%env(int:default:retention_default_days:LOGSWIRE_RETENTION_DAYS)%')]
        private readonly int $retentionDays,
    ) {
    }

    /**
     * @return array{
     *     database_label: string,
     *     payload_label: string,
     *     avg_payload_label: ?string,
     *     occurrences_last_7_days: int,
     *     occurrences_per_day_estimate: ?float,
     *     projected_row_count: ?int,
     *     projected_size_label: ?string,
     * }
     */
    public function capacitySnapshot(): array
    {
        $isPostgres = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $events = (int) $this->db->fetchOne('SELECT COUNT(*) FROM events');
        $payloadBytes = (int) $this->db->fetchOne(sprintf(
            'SELECT COALESCE(SUM(%s), 0) FROM events',
            $isPostgres ? 'OCTET_LENGTH(payload)' : 'LENGTH(payload)',
        ));

        $last7 = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM events WHERE received_at >= ?',
            [new \DateTimeImmutable('-7 days', new \DateTimeZone('UTC'))],
            [Types::DATETIME_IMMUTABLE],
        );
        $perDay = $last7 > 0 ? round($last7 / 7, 1) : null;
        $avgPayload = $events > 0 ? (int) round($payloadBytes / $events) : null;

        $projectedRows = null;
        $projectedSize = null;
        if ($this->retentionDays > 0 && $perDay !== null && $perDay > 0 && $avgPayload !== null) {
            $projectedRows = (int) round($perDay * $this->retentionDays);
            $projectedSize = self::formatBytes((int) round($projectedRows * $avgPayload * 1.15));
        }

        $databaseBytes = $isPostgres
            ? (int) $this->db->fetchOne('SELECT pg_database_size(current_database())')
            : 0;

        return [
            'database_label' => $databaseBytes > 0 ? self::formatBytes($databaseBytes) : '—',
            'payload_label' => self::formatBytes($payloadBytes),
            'avg_payload_label' => $avgPayload !== null ? self::formatBytes($avgPayload) : null,
            'occurrences_last_7_days' => $last7,
            'occurrences_per_day_estimate' => $perDay,
            'projected_row_count' => $projectedRows,
            'projected_size_label' => $projectedSize,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MiB', $bytes / (1024 * 1024));
        }

        return sprintf('%.2f GiB', $bytes / (1024 * 1024 * 1024));
    }
}
