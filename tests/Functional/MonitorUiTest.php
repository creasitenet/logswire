<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Monitor;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class MonitorUiTest extends WebTestCase
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

    public function testListAndDetailRenderStoredMonitor(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $monitor = Monitor::create(Uuid::v4()->toRfc4122(), 'https://example.com/health', 'api-prod');
        $em->persist($monitor);
        $em->flush();

        $client->request('GET', '/dashboard/monitors');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('api-prod', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('https://example.com/health', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('hx-trigger="every 60s"', (string) $client->getResponse()->getContent());

        $client->request('GET', '/dashboard/monitors/'.$monitor->getId());
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('api-prod', $body);
        self::assertStringContainsString('Uptime 24 h', $body);
        self::assertStringContainsString('Daily rollup', $body);
    }

    public function testListFragmentRefreshesWithoutLayout(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $monitor = Monitor::create(Uuid::v4()->toRfc4122(), 'https://example.net/fragment', 'fragment-monitor');
        $em->persist($monitor);
        $em->flush();

        $client->request('GET', '/dashboard/monitors', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="monitors-results"', $body);
        self::assertStringContainsString('hx-trigger="every 60s"', $body);
        self::assertStringContainsString('fragment-monitor', $body);
        self::assertStringNotContainsString('<html', $body);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testLiveFragmentSwapsLiveRegions(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $monitor = Monitor::create(Uuid::v4()->toRfc4122(), 'https://example.net/live', 'live-monitor');
        $em->persist($monitor);
        $em->flush();

        $client->request('GET', '/dashboard/monitors/'.$monitor->getId().'/live');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="monitor-live-meta"', $body);
        self::assertStringContainsString('id="monitor-live-status"', $body);
        self::assertStringContainsString('id="monitor-live-error"', $body);
        self::assertStringContainsString('id="monitor-live-stats"', $body);
        self::assertStringContainsString('id="monitor-availability"', $body);
        self::assertStringContainsString('id="monitor-live-daily"', $body);
        self::assertStringContainsString('id="monitor-live-incidents"', $body);
        self::assertStringContainsString('Day buckets appear after the first successful check.', $body);
        self::assertStringContainsString('hx-swap-oob="true"', $body);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('data-live-pill', $body);
        self::assertStringContainsString('data-ts-countdown', $body);
    }

    public function testLiveResponseOnlyShowsTheErrorBannerWhileDown(): void
    {
        $client = static::createClient();
        $this->login($client);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $down = Monitor::create(Uuid::v4()->toRfc4122(), 'https://example.net/down', 'down-monitor');
        $down->applyProbeResult(Monitor::STATUS_DOWN, 500, 12, 'HTTP 500', time() - 60);
        $down->applyProbeResult(Monitor::STATUS_DOWN, 500, 12, 'HTTP 500', time() - 30);
        $up = Monitor::create(Uuid::v4()->toRfc4122(), 'https://example.net/up', 'up-monitor');
        $up->applyProbeResult(Monitor::STATUS_UP, 200, 20, null, time());
        $em->persist($down);
        $em->persist($up);
        $em->flush();

        $client->request('GET', '/dashboard/monitors/'.$down->getId().'/live');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="monitor-live-error"', $body);
        self::assertStringContainsString('flash-error', $body);
        self::assertStringContainsString('HTTP 500', $body);

        $client->request('GET', '/dashboard/monitors/'.$up->getId().'/live');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="monitor-live-error"', $body);
        self::assertStringNotContainsString('flash-error', $body);
        self::assertStringNotContainsString('HTTP 500', $body);
    }

    public function testCreateRejectsInvalidUrl(): void
    {
        $client = static::createClient();
        $this->login($client);

        $crawler = $client->request('GET', '/dashboard/monitors/new');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Create')->form([
            'name' => 'bad-monitor',
            'url' => 'ftp://example.com',
        ]));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(
            'Only http:// and https:// URLs are supported.',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testCreateThenToggleMonitor(): void
    {
        $client = static::createClient();
        $this->login($client);

        $crawler = $client->request('GET', '/dashboard/monitors/new');
        $client->submit($crawler->selectButton('Create')->form([
            'name' => 'web-frontend',
            'url' => 'https://example.org/status',
            'interval_sec' => '120',
        ]));
        self::assertResponseRedirects();

        $client->followRedirect();
        self::assertStringContainsString('web-frontend', (string) $client->getResponse()->getContent());

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $monitor = $em->getRepository(Monitor::class)->findOneBy(['name' => 'web-frontend']);
        \assert($monitor instanceof Monitor);
        self::assertSame(120, $monitor->getIntervalSec());

        $client->request('POST', '/dashboard/monitors/'.$monitor->getId().'/toggle', [
            '_csrf_token' => $this->csrfToken($client, $monitor->getId()),
            'enabled' => '0',
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(Monitor::class)->find($monitor->getId());
        \assert($reloaded instanceof Monitor);
        self::assertFalse($reloaded->isEnabled());
    }

    private function csrfToken(KernelBrowser $client, string $monitorId): string
    {
        $crawler = $client->request('GET', '/dashboard/monitors/'.$monitorId);

        // Tokens are bound to the token id, not the form: the delete form's
        // "monitor_action" token is valid for the toggle action too.
        return (string) $crawler
            ->filter('form[action$="/delete"] input[name="_csrf_token"]')
            ->first()
            ->attr('value');
    }

    private function login(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'monitor-ui-user']);
        if (!$user instanceof User) {
            $user = (new User())
                ->setUsername('monitor-ui-user')
                ->setRoles(['ROLE_USER'])
                ->setPassword($hasher->hashPassword(new User(), 'MonitorUi1'));
            $em->persist($user);
            $em->flush();
        }

        $client->request('GET', '/');
        $client->submitForm('Sign in', [
            '_username' => 'monitor-ui-user',
            '_password' => 'MonitorUi1',
        ]);
        $client->followRedirect();
    }
}
