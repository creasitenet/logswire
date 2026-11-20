<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectsListTest extends WebTestCase
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

    public function testListShowsEmptyStateThenIssueCounts(): void
    {
        $client = static::createClient();
        $this->login($client);

        $crawler = $client->request('GET', '/dashboard/projects');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No projects yet', $crawler->text());

        $project = $this->createProject('projects-list-'.bin2hex(random_bytes(4)));
        $this->postEnvelope($client, $project);

        $crawler = $client->request('GET', '/dashboard/projects');
        self::assertResponseIsSuccessful();

        $table = $crawler->filter('.project-table');
        self::assertSame(1, $table->count());
        self::assertStringContainsString($project->getName(), $table->text());
        self::assertStringContainsString($project->getSlug(), $table->text());
        self::assertStringContainsString('Receiving', $table->text());

        $row = $table->filter('tbody tr')->first();
        self::assertSame('1', trim($row->filter('td')->eq(2)->text()));

        $openLink = $row->filter('td')->eq(2)->filter('a');
        self::assertSame(1, $openLink->count());
        self::assertStringContainsString('project_id='.$project->getId(), (string) $openLink->attr('href'));
        self::assertStringContainsString('status=unresolved', (string) $openLink->attr('href'));
    }

    private function login(KernelBrowser $client): void
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setUsername('projects-list-user')
            ->setRoles(['ROLE_USER'])
            ->setPassword($hasher->hashPassword(new User(), 'ProjectsList1'));
        $em->persist($user);
        $em->flush();

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'projects-list-user',
            '_password' => 'ProjectsList1',
        ]);
        $client->followRedirect();
    }

    private function createProject(string $name): Project
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->setName($name);
        $project->setSlug($name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function postEnvelope(KernelBrowser $client, Project $project): void
    {
        $body = json_encode(['level' => 'error', 'message' => 'boom'], JSON_THROW_ON_ERROR);
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
}
