<?php

declare(strict_types=1);

namespace App\Tests\Unit\Retention;

use App\Service\Retention\RetentionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RetentionServiceTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                received_at DATETIME NOT NULL
            )
        SQL);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE issues (
                id CHAR(36) PRIMARY KEY,
                last_seen_at DATETIME NOT NULL
            )
        SQL);
    }

    public function testDeletesEventsOlderThanCutoff(): void
    {
        $this->db->executeStatement("INSERT INTO events (received_at) VALUES (datetime('now', '-100 days'))");
        $this->db->executeStatement("INSERT INTO events (received_at) VALUES (datetime('now'))");

        $service = new RetentionService($this->db, new NullLogger(), 90);
        $stats = $service->runOnce();

        self::assertSame(1, $stats['events_deleted']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events'));
    }

    public function testRetentionDisabledWhenDaysZero(): void
    {
        $this->db->executeStatement("INSERT INTO events (received_at) VALUES (datetime('now', '-365 days'))");

        $service = new RetentionService($this->db, new NullLogger(), 0);
        $stats = $service->runOnce();

        self::assertSame(0, $stats['events_deleted']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events'));
    }

    public function testDeletesEveryBatchUntilClean(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->db->executeStatement("INSERT INTO events (received_at) VALUES (datetime('now', '-100 days'))");
        }

        $service = new RetentionService($this->db, new NullLogger(), 90, 2);
        $stats = $service->runOnce();

        self::assertSame(5, $stats['events_deleted']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events'));
    }
}

