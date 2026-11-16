<?php

declare(strict_types=1);

namespace App\Service\Monitor;

use App\Entity\Monitor;
use App\Repository\IncidentRepository;
use App\Repository\MonitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class MonitorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MonitorRepository $monitors,
        private readonly IncidentRepository $incidents,
    ) {
    }

    /**
     * @return list<Monitor>
     */
    public function list(): array
    {
        return $this->monitors->findAllForDashboard();
    }

    public function get(string $id): Monitor
    {
        $monitor = $this->monitors->find($id);
        if (!$monitor instanceof Monitor) {
            throw new NotFoundHttpException('Monitor not found.');
        }

        return $monitor;
    }

    public function create(string $name, string $url, int $intervalSec): Monitor
    {
        [$name, $url, $intervalSec] = self::validate($name, $url, $intervalSec);

        $monitor = Monitor::create(Uuid::v7()->toRfc4122(), $url, $name, $intervalSec);
        $this->em->persist($monitor);
        $this->em->flush();

        return $monitor;
    }

    public function update(Monitor $monitor, string $name, string $url, int $intervalSec, bool $enabled): Monitor
    {
        [$name, $url, $intervalSec] = self::validate($name, $url, $intervalSec);

        $now = time();
        $monitor->updateSettings($name, $url, $intervalSec, $now);
        $this->applyEnabled($monitor, $enabled, $now);
        $this->em->flush();

        return $monitor;
    }

    public function setEnabled(Monitor $monitor, bool $enabled): Monitor
    {
        $this->applyEnabled($monitor, $enabled, time());
        $this->em->flush();

        return $monitor;
    }

    /**
     * Pausing a monitor ends its outage: the open incident is closed and the
     * probe state is forgotten so a resumed monitor starts from scratch. A
     * resume schedules an immediate check instead of waiting for a stale slot.
     */
    private function applyEnabled(Monitor $monitor, bool $enabled, int $now): void
    {
        $wasEnabled = $monitor->isEnabled();
        $monitor->setEnabled($enabled, $now);

        if ($enabled) {
            if (!$wasEnabled) {
                $monitor->setNextCheckAt($now);
            }

            return;
        }

        $this->incidents->findOpenForMonitor($monitor)?->close($now);
        $monitor->resetProbeState($now);
    }

    public function delete(Monitor $monitor): void
    {
        $this->em->remove($monitor);
        $this->em->flush();
    }

    /** Human-readable incident duration: 45s, 5m, 2h05m, 3d04h. */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return \sprintf('%ds', $seconds);
        }
        if ($seconds < 3600) {
            return \sprintf('%dm', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return \sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return \sprintf('%dd%02dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }

    /** @return array<string, mixed> */
    public static function viewData(Monitor $monitor): array
    {
        return [
            'id' => $monitor->getId(),
            'name' => $monitor->getName(),
            'url' => $monitor->getUrl(),
            'interval_sec' => $monitor->getIntervalSec(),
            'enabled' => $monitor->isEnabled(),
            'status' => $monitor->getStatus(),
            'status_since' => $monitor->getStatusSince(),
            'last_checked_at' => $monitor->getLastCheckedAt(),
            'last_status_code' => $monitor->getLastStatusCode(),
            'last_response_ms' => $monitor->getLastResponseMs(),
            'last_error' => $monitor->getLastError(),
            'next_check_at' => $monitor->getNextCheckAt(),
            'created_at' => $monitor->getCreatedAt(),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private static function validate(string $name, string $url, int $intervalSec): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BadRequestHttpException('Name is required (max 120 chars).');
        }

        $url = trim($url);
        $error = self::urlValidationError($url);
        if ($error !== null) {
            throw new BadRequestHttpException($error);
        }
        if (mb_strlen($url) > 2048) {
            throw new BadRequestHttpException('URL is too long (max 2048 chars).');
        }

        return [$name, $url, $intervalSec];
    }

    /**
     * Minimal SSRF guard: absolute http(s) only, valid port. Private networks are
     * allowed (self-hosted). Checked on create/update only — the probe trusts the DB.
     *
     * @return string|null readable reason, null when the URL is usable
     */
    private static function urlValidationError(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'URL must be absolute, e.g. https://example.com/health.';
        }

        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Only http:// and https:// URLs are supported.';
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return 'Port must be between 1 and 65535.';
        }

        return null;
    }
}
