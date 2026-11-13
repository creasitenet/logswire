<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardOverviewTest extends WebTestCase
{
    public function testOverviewRendersForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $this->bootSchema($client);
        $this->login($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Overview');
        self::assertSelectorExists('.kpi-grid');
        self::assertGreaterThan(0, $crawler->filter('.kpi-card')->count());
    }

    private function bootSchema(KernelBrowser $client): void
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
    }

    private function login(KernelBrowser $client): void
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = $em->getRepository(User::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        if ($users->findOneByUsername('overview-user') === null) {
            $user = (new User())
                ->setUsername('overview-user')
                ->setRoles(['ROLE_USER'])
                ->setPassword($hasher->hashPassword(new User(), 'Overview1pass'));
            $em->persist($user);
            $em->flush();
        }

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'overview-user',
            '_password' => 'Overview1pass',
        ]);
        $client->followRedirect();
    }
}
