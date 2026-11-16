<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IngestControllerTest extends WebTestCase
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

    public function testMissingSentryKeyReturnsJson401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/does-not-exist/envelope/', content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"error":"Missing X-Sentry-Auth sentry_key."}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testUnknownProjectReturnsJson404(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/does-not-exist/envelope/',
            server: [
                'HTTP_X_SENTRY_AUTH' => 'Sentry sentry_version=7, sentry_key=deadbeef',
                'CONTENT_TYPE' => 'application/x-sentry-envelope',
            ],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertJsonStringEqualsJsonString(
            '{"error":"project_not_found"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testSentryKeyFirstInAuthHeaderIsAccepted(): void
    {
        $client = static::createClient();
        $project = $this->createProject('ingest-key-first-'.bin2hex(random_bytes(4)));

        $client->request(
            'POST',
            sprintf('/api/%s/envelope/', $project->getSlug()),
            server: [
                'HTTP_X_SENTRY_AUTH' => 'Sentry sentry_key='.$project->getPublicKey().', sentry_version=7',
                'CONTENT_TYPE' => 'application/x-sentry-envelope',
            ],
            content: $this->minimalEnvelope('key first'),
        );

        self::assertResponseStatusCodeSame(202);
    }

    public function testSentryAuthSchemeIsCaseInsensitive(): void
    {
        $client = static::createClient();
        $project = $this->createProject('ingest-lower-scheme-'.bin2hex(random_bytes(4)));

        $client->request(
            'POST',
            sprintf('/api/%s/envelope/', $project->getSlug()),
            server: [
                'HTTP_X_SENTRY_AUTH' => 'sentry sentry_key='.$project->getPublicKey().', sentry_version=7',
                'CONTENT_TYPE' => 'application/x-sentry-envelope',
            ],
            content: $this->minimalEnvelope('lowercase scheme'),
        );

        self::assertResponseStatusCodeSame(202);
    }

    public function testEnvelopeGzipAndFingerprintGrouping(): void
    {
        $client = static::createClient();
        $project = $this->createProject('ingest-func-'.bin2hex(random_bytes(4)));

        $buildEnvelope = static function (string $message): string {
            $event = json_encode([
                'level' => 'error',
                'message' => $message,
                'exception' => ['values' => [['type' => 'GroupedError', 'value' => $message]]],
            ], JSON_THROW_ON_ERROR);
            $itemHeader = json_encode(['type' => 'event', 'length' => \strlen($event)], JSON_THROW_ON_ERROR);

            return "{}\n{$itemHeader}\n{$event}";
        };

        $url = sprintf('/api/%s/envelope/', $project->getSlug());
        $auth = ['HTTP_X_Sentry_Auth' => 'Sentry sentry_version=7, sentry_key='.$project->getPublicKey()];

        $send = static function (string $body) use ($client, $url, $auth): void {
            $client->request(
                'POST',
                $url,
                server: array_merge($auth, [
                    'HTTP_CONTENT_ENCODING' => 'gzip',
                    'CONTENT_TYPE' => 'application/x-sentry-envelope',
                ]),
                content: gzencode($body) ?: '',
            );
        };

        $send($buildEnvelope('first'));
        self::assertResponseStatusCodeSame(202);
        $send($buildEnvelope('second occurrence'));
        self::assertResponseStatusCodeSame(202);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $issues = $em->getRepository(\App\Entity\Issue::class)->findBy(['project' => $project]);
        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->getCount());
    }

    private function minimalEnvelope(string $message): string
    {
        $event = json_encode([
            'level' => 'error',
            'message' => $message,
        ], JSON_THROW_ON_ERROR);
        $itemHeader = json_encode(['type' => 'event', 'length' => \strlen($event)], JSON_THROW_ON_ERROR);

        return "{}\n{$itemHeader}\n{$event}";
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
