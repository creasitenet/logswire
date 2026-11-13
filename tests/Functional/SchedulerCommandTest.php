<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/** Regression: daily prune uses cron; requires dragonmantank/cron-expression. */
final class SchedulerCommandTest extends KernelTestCase
{
    public function testDebugSchedulerListsRetentionPrunes(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $exitCode = $tester->run(['command' => 'debug:scheduler', '--no-ansi' => true]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('PruneErrorRetentionMessage', $tester->getDisplay());
        self::assertStringContainsString('PruneUptimeRetentionMessage', $tester->getDisplay());
    }
}
