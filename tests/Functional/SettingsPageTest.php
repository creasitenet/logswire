<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SettingsPageTest extends WebTestCase
{
    public function testAdminCanOpenSettings(): void
    {
        $client = static::createClient();
        $this->seedAdmin($client, 'settings-admin', 'SettingsPass1');

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'settings-admin',
            '_password' => 'SettingsPass1',
        ]);
        $client->followRedirect();

        $client->request('GET', '/dashboard/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Settings');
    }

    private function seedAdmin(KernelBrowser $client, string $username, string $plainPassword): void
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
