<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'app:db:backup', description: 'Dump the Postgres database with pg_dump')]
final class DbBackupCommand extends PostgresToolCommand
{
    public function __construct(
        Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct($connection);
    }

    protected function configure(): void
    {
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Destination .sql file (must not exist)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $io->error('app:db:backup supports PostgreSQL only.');

            return Command::FAILURE;
        }

        $destination = $input->getOption('output');
        if (!\is_string($destination) || $destination === '') {
            $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His');
            $destination = Path::join($this->projectDir, 'var', 'backups', 'logswire-'.$stamp.'.sql');
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($destination));

        if (is_file($destination)) {
            $io->error(sprintf('Destination already exists: %s', $destination));

            return Command::FAILURE;
        }

        $process = new Process([
            'pg_dump',
            '--format=plain',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-acl',
            ...$this->connectionArguments(),
            '--file='.$destination,
        ]);
        $process->setTimeout(null);
        $this->exportPassword($process);

        try {
            $process->run();
        } catch (ProcessStartFailedException $e) {
            $io->error(sprintf('Could not run pg_dump: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!$process->isSuccessful()) {
            $io->error(sprintf('pg_dump failed: %s', trim($process->getErrorOutput()) ?: 'unknown error'));

            return Command::FAILURE;
        }

        $io->success(sprintf('Backup written to %s', $destination));

        return Command::SUCCESS;
    }
}
