<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Service\Database\DatabaseStatsService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DatabaseStatsServiceTest extends TestCase
{
    public function testCapacitySnapshotEstimatesFromRecentIngest(): void
    {
        $db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $db->executeStatement(<<<'SQL'
            CREATE TABLE events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                received_at DATETIME NOT NULL,
                payload TEXT
            )
        SQL);

        $db->executeStatement("INSERT INTO events (received_at, payload) VALUES (datetime('now'), '{\"a\":1}')");
        $db->executeStatement("INSERT INTO events (received_at, payload) VALUES (datetime('now', '-10 days'), '{\"b\":22}')");

        $service = new DatabaseStatsService($db, 15);
        $snap = $service->capacitySnapshot();

        self::assertGreaterThan(0, $snap['payload_label']);
        self::assertSame(1, $snap['occurrences_last_7_days']);
        self::assertNotNull($snap['projected_row_count']);
    }

    public function testFormatBytes(): void
    {
        self::assertSame('512 B', DatabaseStatsService::formatBytes(512));
        self::assertSame('1.0 KiB', DatabaseStatsService::formatBytes(1024));
    }
}
