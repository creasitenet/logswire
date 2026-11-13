<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    public function testFailedLoginShowsError(): void
    {
        $client = static::createClient();
        $this->seedUser($client, 'login-test', 'LoginTestPass1');

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'login-test',
            '_password' => 'wrong-password',
        ]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.flash-error');
    }

    public function testLoginPersistsSessionAcrossRequests(): void
    {
        $client = static::createClient();
        $this->seedUser($client, 'login-test', 'LoginTestPass1');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $client->submitForm('Sign in', [
            '_username' => 'login-test',
            '_password' => 'LoginTestPass1',
        ]);
        self::assertResponseRedirects('/dashboard');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function seedUser(KernelBrowser $client, string $username, string $plainPassword): void
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = $em->getRepository(User::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $existing = $users->findOneByUsername($username);
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }

        $user = (new User())
            ->setUsername($username)
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setPassword($hasher->hashPassword(new User(), $plainPassword));

        $em->persist($user);
        $em->flush();
    }
}
