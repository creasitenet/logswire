<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Retention\RetentionRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:retention:cleanup', description: 'Delete events and issues past the retention period')]
final class RetentionCleanupCommand extends Command
{
    public function __construct(
        private readonly RetentionRunner $retention,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->retention->runNow();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Retention prune: %d event(s) and %d issue(s) deleted.',
            $stats['events_deleted'],
            $stats['issues_deleted'],
        ));

        return Command::SUCCESS;
    }
}
