<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'app:db:restore', description: 'Replace the live Postgres schema with a pg_dump file')]
final class DbRestoreCommand extends PostgresToolCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('backup', InputArgument::REQUIRED, 'Path to a .sql pg_dump file')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $io->error('app:db:restore supports PostgreSQL only.');

            return Command::FAILURE;
        }

        $backup = (string) $input->getArgument('backup');
        if (!is_file($backup) || !is_readable($backup)) {
            $io->error(sprintf('Backup file not readable: %s', $backup));

            return Command::FAILURE;
        }

        if (!$input->getOption('force') && !$io->confirm(
            sprintf('Drop and restore the live schema from %s? Stop the app and probe first.', $backup),
            false,
        )) {
            $io->writeln('Aborted.');

            return Command::SUCCESS;
        }

        $process = new Process([
            'psql',
            '--set=ON_ERROR_STOP=1',
            '--quiet',
            ...$this->connectionArguments(),
            '--file='.$backup,
        ]);
        $process->setTimeout(null);
        $this->exportPassword($process);

        try {
            $process->run();
        } catch (ProcessStartFailedException $e) {
            $io->error(sprintf('Could not run psql: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!$process->isSuccessful()) {
            $io->error(sprintf('psql failed: %s', trim($process->getErrorOutput()) ?: 'unknown error'));

            return Command::FAILURE;
        }

        $io->success(sprintf('Restored schema from %s.', $backup));

        return Command::SUCCESS;
    }
}
