<?php

declare(strict_types=1);

namespace App\Service\Retention;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Throttled error retention (~1/day). Triggered by the worker schedule (or CLI).
 */
final class RetentionRunner
{
    private const int MIN_INTERVAL_SECONDS = 86_400;

    public function __construct(
        private readonly RetentionService $retention,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $stateFile,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(int:default:retention_default_days:LOGSWIRE_RETENTION_DAYS)%')]
        private readonly int $retentionDays,
    ) {
    }

    public function maybeRun(): void
    {
        if ($this->environment === 'test' || $this->retentionDays <= 0) {
            return;
        }

        if ($this->isThrottled()) {
            return;
        }

        $lock = $this->lockFactory->createLock('logswire.retention', 300.0);
        if (!$lock->acquire()) {
            return;
        }

        try {
            // Re-check after lock: another process may have finished retention.
            if ($this->isThrottled()) { // @phpstan-ignore if.alwaysFalse (file state can change under lock)
                return;
            }

            $this->retention->runOnce();
            $this->writeLastRun($this->clock->now());
        } catch (\Throwable $e) {
            $this->logger->error('Retention maybeRun failed', ['error' => $e->getMessage()]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Full prune (CLI / Settings). Not time-budgeted.
     *
     * @return array{events_deleted: int, issues_deleted: int}
     */
    public function runNow(): array
    {
        $stats = $this->retention->runOnce();
        if ($this->retentionDays > 0) {
            $this->writeLastRun($this->clock->now());
        }

        return $stats;
    }

    public function lastRunAt(): ?\DateTimeImmutable
    {
        if (!is_file($this->stateFile)) {
            return null;
        }

        $raw = trim((string) file_get_contents($this->stateFile));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$raw))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function isEnabled(): bool
    {
        return $this->retentionDays > 0;
    }

    private function isThrottled(): bool
    {
        $last = $this->lastRunAt();
        if ($last === null) {
            return false;
        }

        $elapsed = $this->clock->now()->getTimestamp() - $last->getTimestamp();

        return $elapsed < self::MIN_INTERVAL_SECONDS;
    }

    private function writeLastRun(\DateTimeImmutable $at): void
    {
        $path = $this->stateFile;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create retention state directory.');
        }

        file_put_contents($path, (string) $at->getTimestamp());
    }
}
