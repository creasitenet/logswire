<?php

declare(strict_types=1);

namespace App\Tests\Unit\Retention;

use App\Service\Retention\RetentionRunner;
use App\Service\Retention\RetentionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RetentionRunnerTest extends TestCase
{
    private Connection $db;

    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir().'/lw-retention-test-'.bin2hex(random_bytes(4)).'.run';
        $dbPath = sys_get_temp_dir().'/lw-retention-test-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $dbPath,
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

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        $path = $this->db->getParams()['path'] ?? null;
        if (\is_string($path)) {
            @unlink($path);
        }
    }

    public function testMaybeRunRespectsThrottle(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $service = new RetentionService($this->db, new NullLogger(), 90);
        $runner = $this->runner($service, $clock);

        file_put_contents($this->stateFile, (string) $clock->now()->getTimestamp());

        $this->db->executeStatement("INSERT INTO events (received_at) VALUES (datetime('now', '-100 days'))");
        $runner->maybeRun();

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events'));

        $clock->sleep(86_400);
        $runner->maybeRun();

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events'));
    }

    public function testRunNowBypassesThrottle(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00 UTC');
        $service = new RetentionService($this->db, new NullLogger(), 90);
        $runner = $this->runner($service, $clock);

        file_put_contents($this->stateFile, (string) $clock->now()->getTimestamp());
        $this->db->executeStatement("INSERT INTO events (received_at) VALUES (datetime('now', '-100 days'))");

        $stats = $runner->runNow();

        self::assertSame(1, $stats['events_deleted']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events'));
    }

    private function runner(RetentionService $service, MockClock $clock): RetentionRunner
    {
        return new RetentionRunner(
            $service,
            new LockFactory(new InMemoryStore()),
            $clock,
            new NullLogger(),
            $this->stateFile,
            'prod',
            90,
        );
    }
}
