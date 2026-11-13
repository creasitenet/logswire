<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ProbeLoopCommand;
use App\Probe\ProbeScheduler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class ProbeLoopCommandTest extends TestCase
{
    private string $heartbeatFile;

    protected function setUp(): void
    {
        $this->heartbeatFile = \sys_get_temp_dir().'/logswire-probe-'.bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        @\unlink($this->heartbeatFile);
    }

    public function testWritesHeartbeatOnTick(): void
    {
        $command = $this->command();

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $this->runOneLoop($command, $tester));

        self::assertFileExists($this->heartbeatFile);

        $heartbeat = (int) \file_get_contents($this->heartbeatFile);
        self::assertGreaterThanOrEqual(time() - 2, $heartbeat, 'Heartbeat must hold the last tick timestamp.');
    }

    private function command(): ProbeLoopCommand
    {
        $scheduler = $this->createMock(ProbeScheduler::class);

        $command = new ProbeLoopCommand($scheduler, 1, $this->heartbeatFile);

        $scheduler->method('tick')->willReturnCallback(static function () use ($command): int {
            $command->handleSignal(15);

            return 0;
        });

        return $command;
    }

    private function runOneLoop(ProbeLoopCommand $command, CommandTester $tester): int
    {
        (new \ReflectionProperty(ProbeLoopCommand::class, 'running'))->setValue($command, true);

        return $tester->execute([]);
    }
}
