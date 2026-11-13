<?php

declare(strict_types=1);

namespace App\Command;

use App\Probe\ProbeScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:probe:loop', description: 'Run HTTP probes on schedule until SIGTERM/SIGINT.')]
final class ProbeLoopCommand extends Command implements SignalableCommandInterface
{
    private bool $running = true;

    private bool $heartbeatWarned = false;

    public function __construct(
        private readonly ProbeScheduler $scheduler,
        private readonly int $tickSeconds,
        private readonly string $heartbeatFile,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \function_exists('pcntl_signal') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->running = false;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, $this->tickSeconds);
        $output->writeln(\sprintf('<info>Probe loop started (tick every %ds).</info>', $interval));

        while ($this->running) {
            $started = time();
            $processed = $this->scheduler->tick();
            if ($processed > 0) {
                $output->writeln(\sprintf('Probed %d monitor(s).', $processed));
            }
            $this->heartbeat($output);

            $elapsed = time() - $started;
            sleep(max(1, $interval - $elapsed));
        }

        $output->writeln('<info>Probe loop stopped.</info>');

        return Command::SUCCESS;
    }

    /**
     * Docker healthchecks read this file: a stale timestamp means the loop is
     * stuck (e.g. a hung probe), while a live process alone would still look ok.
     */
    private function heartbeat(OutputInterface $output): void
    {
        if (@file_put_contents($this->heartbeatFile, (string) time()) !== false) {
            return;
        }

        if (!$this->heartbeatWarned) {
            $this->heartbeatWarned = true;
            $output->writeln(\sprintf('<comment>Cannot write probe heartbeat to %s.</comment>', $this->heartbeatFile));
        }
    }
}
