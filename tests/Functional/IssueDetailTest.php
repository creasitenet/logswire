<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class IssueDetailTest extends WebTestCase
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

    public function testIssueDetailResolvesUuidV7FromSqlite(): void
    {
        $client = static::createClient();
        $project = $this->createProject('issue-detail-'.bin2hex(random_bytes(4)));

        $this->postEnvelope($client, $project, [
            'level' => 'error',
            'message' => 'detail smoke',
            'exception' => ['values' => [['type' => 'DetailError', 'value' => 'detail smoke']]],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $issues = $em->getRepository(\App\Entity\Issue::class)->findBy(['project' => $project]);
        self::assertCount(1, $issues);

        $issueId = Uuid::fromString($issues[0]->getId());
        $issueRepo = $em->getRepository(\App\Entity\Issue::class);
        \assert($issueRepo instanceof \App\Repository\IssueRepository);
        $reloaded = $issueRepo->findOneById($issueId);
        self::assertNotNull($reloaded);
        self::assertSame($issues[0]->getFingerprint(), $reloaded->getFingerprint());
    }

    public function testMalformedIssueIdReturns404(): void
    {
        $client = static::createClient();
        $this->login($client);

        $client->request('GET', '/issues/------------------------------------');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIssueDetailShowsProjectStackAndOccurrences(): void
    {
        $client = static::createClient();
        $this->login($client);

        $project = $this->createProject('occurrence-meta-'.bin2hex(random_bytes(4)));
        $this->postEnvelope($client, $project, [
            'level' => 'warning',
            'message' => 'checkout failed',
            'environment' => 'production',
            'release' => '1.4.2',
            'request' => [
                'url' => 'https://shop.example/checkout',
                'method' => 'POST',
                'headers' => ['User-Agent' => 'IssueDetailTestBrowser/1.0'],
            ],
            'user' => ['ip_address' => '192.0.2.44'],
            'contexts' => ['browser' => ['name' => 'Firefox']],
            'exception' => [
                'values' => [[
                    'type' => 'CheckoutError',
                    'value' => 'checkout failed',
                    'stacktrace' => ['frames' => [
                        ['filename' => 'src/Checkout.php', 'function' => 'pay', 'lineno' => 12, 'in_app' => true],
                    ]],
                ]],
            ],
        ]);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $issue = $em->getRepository(\App\Entity\Issue::class)->findOneBy(['project' => $project]);
        self::assertNotNull($issue);

        $crawler = $client->request('GET', '/issues/'.$issue->getId());
        self::assertResponseIsSuccessful();

        self::assertStringContainsString($project->getName(), $crawler->filter('.issue-header-meta')->text());
        self::assertGreaterThan(0, $crawler->filter('.issue-detail-grid > .issue-panel .stacktrace li')->count());
        self::assertGreaterThan(0, $crawler->filter('.occurrence-history li')->count());
        self::assertStringContainsString('production', $crawler->filter('.occurrence-history')->text());
        self::assertStringContainsString('192.0.2.44', $crawler->filter('.occurrence-history')->text());
        self::assertStringContainsString('IssueDetailTestBrowser', $crawler->filter('.issue-context-dl')->text());
        self::assertCount(0, $crawler->filter('.occurrence-row'));
    }

    public function testIssueDetailShowsNewestOccurrenceWhenReceivedAtTies(): void
    {
        $client = static::createClient();
        $this->login($client);

        $project = $this->createProject('occurrence-tie-'.bin2hex(random_bytes(4)));
        $frame = ['filename' => 'src/Cart.php', 'function' => 'total', 'lineno' => 42, 'in_app' => true];

        foreach (['staging', 'production'] as $environment) {
            $this->postEnvelope($client, $project, [
                'level' => 'error',
                'message' => 'tie',
                'environment' => $environment,
                'request' => ['url' => 'https://shop.example/'.$environment],
                'exception' => [
                    'values' => [[
                        'type' => 'TieError',
                        'value' => 'tie',
                        'stacktrace' => ['frames' => [$frame]],
                    ]],
                ],
            ]);
        }

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $issue = $em->getRepository(\App\Entity\Issue::class)->findOneBy(['project' => $project]);
        self::assertNotNull($issue);

        // Ingest stores received_at with second precision: two occurrences can tie.
        $em->getConnection()->executeStatement(
            'UPDATE events SET received_at = ? WHERE issue_id = ?',
            ['2026-11-13 12:00:00', $issue->getId()],
        );
        $em->clear();

        $crawler = $client->request('GET', '/issues/'.$issue->getId());
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.issue-panel .stacktrace li')->count());
        self::assertStringNotContainsString('No stacktrace', $crawler->filter('.issue-panel')->first()->text());
        self::assertStringContainsString('production', $crawler->filter('.issue-context-dl')->text());
    }

    private function login(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        if ($em->getRepository(User::class)->findOneBy(['username' => 'issue-detail-user']) === null) {
            $user = (new User())
                ->setUsername('issue-detail-user')
                ->setRoles(['ROLE_USER'])
                ->setPassword($hasher->hashPassword(new User(), 'IssueDetail1'));
            $em->persist($user);
            $em->flush();
        }

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'issue-detail-user',
            '_password' => 'IssueDetail1',
        ]);
        $client->followRedirect();
    }

    /** @param array<string, mixed> $event */
    private function postEnvelope(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, Project $project, array $event): void
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $itemHeader = json_encode(['type' => 'event', 'length' => \strlen($body)], JSON_THROW_ON_ERROR);

        $client->request(
            'POST',
            sprintf('/api/%s/envelope/', $project->getSlug()),
            server: [
                'HTTP_X_Sentry_Auth' => 'Sentry sentry_version=7, sentry_key='.$project->getPublicKey(),
                'CONTENT_TYPE' => 'application/x-sentry-envelope',
            ],
            content: "{}\n{$itemHeader}\n{$body}",
        );
        self::assertResponseStatusCodeSame(202);
    }

    private function createProject(string $name): Project
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->setName($name);
        $project->setSlug(str_replace(' ', '-', strtolower($name)));
        $em->persist($project);
        $em->flush();

        return $project;
    }
}
