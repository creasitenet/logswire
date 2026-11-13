<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Service\Issue\IssueLevel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class IssueListFilterTest extends WebTestCase
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

    public function testIssuesListHtmxHonoursSeverity(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'htmx-filter-proj');
        $this->createIssue($em, $project, 'only-error', IssueLevel::ERROR);
        $em->flush();

        $client->request(
            'GET',
            '/issues?severity=error',
            server: ['HTTP_HX-Request' => 'true'],
        );
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('only-error', $client->getResponse()->getContent());

        $client->request('GET', '/issues?severity=warn', server: ['HTTP_HX-Request' => 'true']);
        self::assertStringNotContainsString('only-error', $client->getResponse()->getContent());
    }

    public function testLevelFilterUsesExactStoredValue(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'level-filter-proj');

        $this->createIssue($em, $project, 'err-one', IssueLevel::ERROR);
        $this->createIssue($em, $project, 'warn-one', IssueLevel::WARNING);
        $em->flush();

        /** @var IssueRepository $issues */
        $issues = $em->getRepository(Issue::class);

        $projectUuid = \Symfony\Component\Uid\Uuid::fromString($project->getId());

        self::assertCount(1, $issues->findFiltered(null, $projectUuid, null, IssueLevel::ERROR, null, null, null, null, 50, 0));
        self::assertCount(1, $issues->findFiltered(null, $projectUuid, null, IssueLevel::WARNING, null, null, null, null, 50, 0));
        self::assertCount(2, $issues->findFiltered(null, $projectUuid, null, null, null, null, null, null, 50, 0));
    }

    public function testEnvironmentAndReleaseUseDenormalizedColumns(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'env-release-filter-proj');

        $prod = $this->createIssue($em, $project, 'prod-issue', IssueLevel::ERROR);
        $prod->setLastEnvironment('Production');
        $prod->setLastRelease('1.2.3');

        $staging = $this->createIssue($em, $project, 'staging-issue', IssueLevel::ERROR);
        $staging->setLastEnvironment('staging');
        $staging->setLastRelease('2.0.0');
        $em->flush();

        /** @var IssueRepository $issues */
        $issues = $em->getRepository(Issue::class);
        $projectUuid = \Symfony\Component\Uid\Uuid::fromString($project->getId());

        self::assertCount(1, $issues->findFiltered(null, $projectUuid, null, null, null, null, 'production', null, 50, 0));
        self::assertCount(1, $issues->findFiltered(null, $projectUuid, null, null, null, null, null, '2.0.0', 50, 0));
        self::assertCount(0, $issues->findFiltered(null, $projectUuid, null, null, null, null, 'production', '2.0.0', 50, 0));
    }

    public function testUntilDateFilterIncludesTheWholeDay(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'until-filter-proj');
        $this->createIssue($em, $project, 'seen-today', IssueLevel::ERROR);
        $em->flush();

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $client->request('GET', '/issues?until='.$today, server: ['HTTP_HX-Request' => 'true']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('seen-today', $client->getResponse()->getContent());

        $client->request('GET', '/issues?until=2020-01-01', server: ['HTTP_HX-Request' => 'true']);
        self::assertStringNotContainsString('seen-today', $client->getResponse()->getContent());
    }

    public function testResolveFromListRedirectsBackToFilters(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em, 'row-resolve-proj');
        $issue = $this->createIssue($em, $project, 'row-resolve-issue', IssueLevel::ERROR);
        $em->flush();

        $crawler = $client->request('GET', '/issues');
        $form = $crawler->filter('form[action="/issues/'.$issue->getId().'/resolve"]')->form();
        $client->submit($form);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/issues?', $location);
        self::assertStringContainsString('status=all', $location);
        self::assertStringContainsString('page=1', $location);

        $issueId = $issue->getId();
        $em->clear();
        $stored = $em->find(Issue::class, $issueId);
        self::assertNotNull($stored);
        self::assertSame(Issue::STATUS_RESOLVED, $stored->getStatus());
    }

    private function login(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        if ($em->getRepository(User::class)->findOneBy(['username' => 'issues-filter-user']) === null) {
            $user = (new User())
                ->setUsername('issues-filter-user')
                ->setRoles(['ROLE_USER'])
                ->setPassword($hasher->hashPassword(new User(), 'IssuesFilter1'));
            $em->persist($user);
            $em->flush();
        }

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'issues-filter-user',
            '_password' => 'IssuesFilter1',
        ]);
        $client->followRedirect();
    }

    private function createProject(EntityManagerInterface $em, string $slug): Project
    {
        $project = new Project();
        $project->setName($slug);
        $project->setSlug($slug);
        $em->persist($project);

        return $project;
    }

    private function createIssue(EntityManagerInterface $em, Project $project, string $fp, int $level): Issue
    {
        $issue = new Issue();
        $issue->setProject($project);
        $issue->setFingerprint($fp);
        $issue->setTitle($fp);
        $issue->setLevel($level);
        $em->persist($issue);

        return $issue;
    }
}

