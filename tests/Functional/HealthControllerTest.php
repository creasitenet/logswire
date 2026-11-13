<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Retention\RetentionRunner;
use App\Service\Retention\RetentionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthzReportsOkWithoutRunningRetention(): void
    {
        $client = static::createClient();

        $runner = $this->createMock(RetentionRunner::class);
        $runner->expects(self::never())->method('maybeRun');
        $runner->expects(self::never())->method('runNow');
        static::getContainer()->set(RetentionRunner::class, $runner);

        $retention = $this->createMock(RetentionService::class);
        $retention->expects(self::never())->method('runOnce');
        static::getContainer()->set(RetentionService::class, $retention);

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok', 'db' => 'up'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
