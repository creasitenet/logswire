<?php

declare(strict_types=1);

namespace App\Service\Issue;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Normalised query-string filters for the issue list: the controller only
 * hands typed values to IssueService and the templates.
 */
final readonly class IssueFilters
{
    private const array SORTABLE = ['level', 'title', 'project', 'status', 'count', 'last_seen'];

    private function __construct(
        public ?string $status,
        public ?string $projectId,
        public ?string $q,
        public ?int $levelExact,
        public ?\DateTimeImmutable $since,
        public ?\DateTimeImmutable $until,
        public ?string $environment,
        public ?string $release,
        public ?string $sort,
        public string $sortDir,
        private ?string $severity,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $status = $request->query->getString('status') ?: null;
        if ($status === 'all') {
            $status = null;
        }
        $severity = self::queryString($request, 'severity') ?? self::queryString($request, 'level');
        $sort = $request->query->getString('sort') ?: null;
        if ($sort !== null && !\in_array($sort, self::SORTABLE, true)) {
            $sort = null;
        }

        return new self(
            status: $status,
            projectId: $request->query->getString('project_id') ?: null,
            q: $request->query->getString('q') ?: null,
            levelExact: IssueLevel::fromFilter($severity),
            since: self::parseDate($request->query->getString('since') ?: null),
            until: self::parseDate($request->query->getString('until') ?: null, endOfDay: true),
            environment: $request->query->getString('environment') ?: null,
            release: $request->query->getString('release') ?: null,
            sort: $sort,
            sortDir: strtolower($request->query->getString('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc',
            severity: $severity,
        );
    }

    public function isActive(): bool
    {
        return $this->projectId !== null
            || $this->q !== null
            || $this->levelExact !== null
            || $this->environment !== null
            || $this->release !== null
            || $this->since !== null
            || $this->until !== null
            || $this->status !== null
            || $this->sort !== null;
    }

    /**
     * Form state echoed back into the filter controls (nulls dropped).
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return array_filter([
            'status' => $this->status ?? 'all',
            'project_id' => $this->projectId,
            'q' => $this->q,
            'severity' => $this->severity,
            'since' => $this->since?->format(\DateTimeInterface::ATOM),
            'until' => $this->until?->format(\DateTimeInterface::ATOM),
            'environment' => $this->environment,
            'release' => $this->release,
            'sort' => $this->sort,
            'sort_dir' => $this->sortDir,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private static function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function parseDate(?string $raw, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new BadRequestHttpException('Invalid date: '.$raw);
        }

        // Date-only "until" values should include the whole day.
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $date->setTime(23, 59, 59);
        }

        return $date;
    }
}
