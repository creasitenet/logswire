<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\Issue;
use App\Entity\Project;
use App\Repository\IssueRepository;
use App\Service\Ingest\IngestService;
use App\Service\Issue\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class IngestServiceTest extends WebTestCase
{
    private static bool $schemaReady = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$schemaReady) {
            return;
        }

        self::ensureKernelShutdown();
        static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        self::$schemaReady = true;
        self::ensureKernelShutdown();
    }

    public function testSameFingerprintGroupsIntoOneIssueAndIncrementsCount(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-group-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $payload = $this->errorPayload('RuntimeException', 'boom');

        $first = $ingest->ingestPayloads($project, [$payload]);
        $second = $ingest->ingestPayloads($project, [$payload]);

        self::assertCount(1, $first->events);
        self::assertCount(1, $second->events);

        /** @var list<Issue> $issues */
        $issues = $em->getRepository(Issue::class)->findBy(['project' => $project]);
        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->getCount());
        self::assertSame('RuntimeException: boom', $issues[0]->getTitle());

        self::assertSame(2, $em->getRepository(Event::class)->count(['project' => $project]));
    }

    public function testSameFingerprintWithinOneBatchCreatesASingleIssue(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-batch-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $payload = $this->errorPayload('RuntimeException', 'boom');
        $result = $ingest->ingestPayloads($project, [$payload, $payload, $payload]);

        self::assertCount(3, $result->events);

        /** @var list<Issue> $issues */
        $issues = $em->getRepository(Issue::class)->findBy(['project' => $project]);
        self::assertCount(1, $issues);
        self::assertSame(3, $issues[0]->getCount());
        self::assertSame(3, $em->getRepository(Event::class)->count(['project' => $project]));
    }

    public function testDifferentFingerprintsCreateSeparateIssues(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-split-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $ingest->ingestPayloads($project, [$this->errorPayload('RuntimeException', 'boom')]);
        $ingest->ingestPayloads($project, [$this->errorPayload('LogicException', 'nope')]);

        /** @var list<Issue> $issues */
        $issues = $em->getRepository(Issue::class)->findBy(['project' => $project]);
        self::assertCount(2, $issues);
        foreach ($issues as $issue) {
            self::assertSame(1, $issue->getCount());
        }
    }

    public function testIngestDenormalizesLastEnvironmentAndReleaseForFilters(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-env-release-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $ingest->ingestPayloads($project, [
            $this->errorPayload('RuntimeException', 'boom', 'Production', '1.2.3'),
        ]);
        $ingest->ingestPayloads($project, [
            $this->errorPayload('RuntimeException', 'boom', 'staging', '2.0.0'),
        ]);

        /** @var list<Issue> $issues */
        $issues = $em->getRepository(Issue::class)->findBy(['project' => $project]);
        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->getCount());
        self::assertSame('staging', $issues[0]->getLastEnvironment());
        self::assertSame('2.0.0', $issues[0]->getLastRelease());

        /** @var IssueRepository $repository */
        $repository = $em->getRepository(Issue::class);
        $projectId = Uuid::fromString($project->getId());

        self::assertCount(1, $repository->findFiltered(null, $projectId, null, null, null, null, 'staging', null, 50, 0));
        self::assertCount(0, $repository->findFiltered(null, $projectId, null, null, null, null, 'production', null, 50, 0));
        self::assertCount(1, $repository->findFiltered(null, $projectId, null, null, null, null, null, '2.0.0', 50, 0));
        self::assertCount(0, $repository->findFiltered(null, $projectId, null, null, null, null, null, '1.2.3', 50, 0));
    }

    public function testIngestPersistsRequestContextColumns(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-context-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $ingest->ingestPayloads($project, [[
            'level' => 'error',
            'message' => 'ctx',
            'environment' => 'staging',
            'contexts' => ['browser' => ['name' => 'Chrome'], 'os' => ['name' => 'macOS']],
            'request' => [
                'url' => 'https://app.example.com/cart',
                'method' => 'GET',
                'headers' => ['User-Agent' => 'SmokeBot/2'],
            ],
            'user' => ['ip_address' => '198.51.100.4'],
            'exception' => ['values' => [['type' => 'E', 'value' => 'ctx']]],
        ]]);

        /** @var Event|null $event */
        $event = $em->getRepository(Event::class)->findOneBy(['project' => $project]);
        self::assertNotNull($event);
        self::assertSame('GET', $event->getRequestMethod());
        self::assertSame('https://app.example.com/cart', $event->getRequestUrl());
        self::assertSame('Chrome', $event->getBrowserName());
        self::assertSame('macOS', $event->getOsName());
        self::assertSame('198.51.100.4', $event->getClientIp());
        self::assertSame('SmokeBot/2', $event->getUserAgent());
    }

    public function testOnlyLatestOccurrenceKeepsFullPayload(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-payload-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $ingest->ingestPayloads($project, [$this->errorPayload('RuntimeException', 'boom')]);
        $ingest->ingestPayloads($project, [$this->errorPayload('RuntimeException', 'boom')]);

        $projectId = $project->getId();
        $em->clear();
        $storedProject = $em->find(Project::class, $projectId);
        self::assertNotNull($storedProject);

        /** @var list<Event> $events */
        $events = $em->getRepository(Event::class)->findBy(['project' => $storedProject], ['id' => 'ASC']);
        self::assertCount(2, $events);
        self::assertNull($events[0]->getPayload());
        self::assertNotNull($events[1]->getPayload());
        self::assertNotNull($events[0]->getStackHash());
        self::assertSame($events[0]->getStackHash(), $events[1]->getStackHash());
    }

    public function testDivergentStackKeepsOnePayloadPerDistinctStacktrace(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-diverged-payload-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $base = $this->errorPayload('RuntimeException', 'boom', fingerprint: ['runtime-boom']);
        $diverged = $this->errorPayload(
            'RuntimeException',
            'boom',
            extraFrames: [['filename' => 'src/Bar.php', 'function' => 'deeper', 'lineno' => 7, 'in_app' => true]],
            fingerprint: ['runtime-boom'],
        );

        $ingest->ingestPayloads($project, [$base]);
        $ingest->ingestPayloads($project, [$base]);
        $ingest->ingestPayloads($project, [$diverged]);
        $ingest->ingestPayloads($project, [$diverged]);

        $projectId = $project->getId();
        $em->clear();
        $storedProject = $em->find(Project::class, $projectId);
        self::assertNotNull($storedProject);

        /** @var list<Event> $events */
        $events = $em->getRepository(Event::class)->findBy(['project' => $storedProject], ['id' => 'ASC']);
        self::assertCount(4, $events);

        // One payload per distinct stacktrace: the newest of each variant only.
        self::assertNull($events[0]->getPayload());
        self::assertNotNull($events[1]->getPayload());
        self::assertNull($events[2]->getPayload());
        self::assertNotNull($events[3]->getPayload());
        self::assertNotSame($events[1]->getStackHash(), $events[3]->getStackHash());

        $issue = $em->getRepository(Issue::class)->findOneBy(['project' => $storedProject]);
        self::assertNotNull($issue);
        self::assertSame(4, $issue->getCount());
    }

    public function testDivergentStacktraceIsFlaggedOnIssue(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-divergence-proj');
        $ingest = $client->getContainer()->get(IngestService::class);
        $issueService = $client->getContainer()->get(IssueService::class);

        $ingest->ingestPayloads($project, [
            $this->errorPayload('RuntimeException', 'boom', fingerprint: ['runtime-boom']),
        ]);
        $ingest->ingestPayloads($project, [
            $this->errorPayload(
                'RuntimeException',
                'boom',
                extraFrames: [['filename' => 'src/Bar.php', 'function' => 'deeper', 'lineno' => 7, 'in_app' => true]],
                fingerprint: ['runtime-boom'],
            ),
        ]);

        $projectId = $project->getId();
        $em->clear();
        $storedProject = $em->find(Project::class, $projectId);
        self::assertNotNull($storedProject);

        $issue = $em->getRepository(Issue::class)->findOneBy(['project' => $storedProject]);
        self::assertNotNull($issue);

        $detail = $issueService->serializeDetail($issue);
        $stacktrace = $detail['stacktrace'];
        self::assertIsArray($stacktrace);

        self::assertSame(2, $detail['count']);
        self::assertTrue($detail['stack_diverged'] === true);
        self::assertCount(2, $stacktrace);
    }

    public function testResolvedIssueReopensOnNewEvent(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'ingest-reopen-proj');
        $ingest = $client->getContainer()->get(IngestService::class);

        $ingest->ingestPayloads($project, [$this->errorPayload('RuntimeException', 'boom')]);

        $issue = $em->getRepository(Issue::class)->findOneBy(['project' => $project]);
        self::assertNotNull($issue);
        $issue->setStatus(Issue::STATUS_RESOLVED);
        $em->flush();

        $issueId = $issue->getId();
        $ingest->ingestPayloads($project, [$this->errorPayload('RuntimeException', 'boom')]);

        $em->clear();
        $stored = $em->find(Issue::class, $issueId);
        self::assertNotNull($stored);
        self::assertSame(Issue::STATUS_UNRESOLVED, $stored->getStatus());
    }

    /**
     * @param list<array<string, mixed>>|null $extraFrames
     * @param list<string>|null $fingerprint
     *
     * @return array<string, mixed>
     */
    private function errorPayload(
        string $type,
        string $value,
        ?string $environment = null,
        ?string $release = null,
        ?array $extraFrames = null,
        ?array $fingerprint = null,
    ): array {
        $frames = [
            ['filename' => 'src/Foo.php', 'function' => 'run', 'lineno' => 42, 'in_app' => true],
        ];
        if ($extraFrames !== null) {
            $frames = [...$frames, ...$extraFrames];
        }

        $payload = [
            'level' => 'error',
            'exception' => [
                'values' => [[
                    'type' => $type,
                    'value' => $value,
                    'stacktrace' => [
                        'frames' => $frames,
                    ],
                ]],
            ],
        ];

        if ($environment !== null) {
            $payload['environment'] = $environment;
        }
        if ($release !== null) {
            $payload['release'] = $release;
        }
        if ($fingerprint !== null) {
            $payload['fingerprint'] = $fingerprint;
        }

        return $payload;
    }

    private function createProject(EntityManagerInterface $em, string $slug): Project
    {
        $project = new Project();
        $project->setName($slug);
        $project->setSlug($slug);
        $em->persist($project);
        $em->flush();

        return $project;
    }
}
