<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\Ingest\IngestLimits;
use App\Service\Ingest\IngestService;
use App\Service\Ingest\SentryEnvelopeParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sentry-compatible ingestion endpoints (public, DSN key auth).
 */
final class IngestController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly IngestService $ingest,
        private readonly SentryEnvelopeParser $envelopeParser,
        private readonly IngestLimits $limits,
        #[Autowire(service: 'limiter.api_write')]
        private readonly RateLimiterFactory $apiWriteLimiter,
        #[Autowire(service: 'limiter.api_ingest_ip')]
        private readonly RateLimiterFactory $ingestIpLimiter,
    ) {
    }

    #[Route('/api/{slug}/envelope/', name: 'ingest_envelope', methods: ['POST'])]
    public function envelope(string $slug, Request $request): JsonResponse
    {
        $this->throttle($this->ingestIpLimiter, 'ip:'.($request->getClientIp() ?? 'unknown'));

        $project = $this->resolveProject($slug, $request);
        $this->throttle($this->apiWriteLimiter, 'ingest:'.$project->getPublicKey());

        $body = $this->readBody($request);
        $parsed = $this->envelopeParser->parse($body);
        $result = $this->ingest->ingestEnvelope($project, $parsed);

        return $this->acceptedResponse($result->events, $result->dropped);
    }

    /**
     * @param list<Event> $events
     */
    private function acceptedResponse(array $events, int $dropped): JsonResponse
    {
        $ids = array_map(
            static fn (Event $event) => $event->getId() !== null ? (string) $event->getId() : null,
            $events,
        );

        return new JsonResponse([
            'id' => $ids[0] ?? null,
            'ids' => array_values(array_filter($ids, static fn ($id) => $id !== null)),
            'events' => \count($events),
            'dropped' => $dropped,
        ], 202);
    }

    private function resolveProject(string $slug, Request $request): Project
    {
        $slug = trim($slug);
        if ($slug === '' || \strlen($slug) > 80) {
            throw new NotFoundHttpException('project_not_found');
        }

        $sentKey = $this->extractSentryKey($request);
        if ($sentKey === null || $sentKey === '') {
            throw new UnauthorizedHttpException('', 'Missing X-Sentry-Auth sentry_key.');
        }

        $project = $this->projects->findOneBySlug($slug);
        if (!$project instanceof Project || !hash_equals((string) $project->getPublicKey(), $sentKey)) {
            throw new NotFoundHttpException('project_not_found');
        }

        return $project;
    }

    private function extractSentryKey(Request $request): ?string
    {
        $header = trim($request->headers->get('X-Sentry-Auth', ''));

        // SDKs prefix the pair list with the "Sentry" scheme:
        // "Sentry sentry_version=7, sentry_key=…". It is not part of the first
        // pair, so strip it before splitting — otherwise a header whose first
        // pair is sentry_key (the order some curl examples and clients use)
        // would be rejected with a 401.
        $header = preg_replace('/^Sentry\s+/i', '', $header) ?? $header;

        if ($header !== '') {
            foreach (explode(',', $header) as $part) {
                $kv = array_map('trim', explode('=', $part, 2));
                if (\count($kv) === 2 && strtolower($kv[0]) === 'sentry_key') {
                    return $kv[1];
                }
            }
        }

        $query = $request->query->get('sentry_key');
        if (\is_string($query) && $query !== '') {
            return $query;
        }

        return null;
    }

    private function throttle(RateLimiterFactory $factory, string $key): void
    {
        $limiter = $factory->create($key);
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retry = $limit->getRetryAfter();
            $seconds = max(1, $retry->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'rate_limited');
        }
    }

    private function readBody(Request $request): string
    {
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && $contentLength !== '') {
            $len = (int) $contentLength;
            if ($len > $this->limits->maxBodyBytes) {
                throw new HttpException(413, 'payload_too_large');
            }
        }

        $raw = $request->getContent();
        if (strtolower((string) $request->headers->get('Content-Encoding', '')) === 'gzip') {
            if (\strlen($raw) > $this->limits->maxBodyBytes) {
                throw new HttpException(413, 'payload_too_large');
            }

            // Bound the decoded size so a compression bomb cannot exhaust memory.
            $decoded = @gzdecode($raw, $this->limits->maxBodyBytes + 1);
            if ($decoded === false) {
                throw new BadRequestHttpException('Invalid gzip body.');
            }
            $raw = $decoded;
        }

        if (\strlen($raw) > $this->limits->maxBodyBytes) {
            throw new HttpException(413, 'payload_too_large');
        }

        return $raw;
    }
}
