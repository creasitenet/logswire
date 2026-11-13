<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/**
 * Shared plumbing for commands that shell out to the Postgres client tools
 * (pg_dump, psql): forwards the Doctrine connection parameters as CLI flags and
 * exports the password through PGPASSWORD instead of the command line.
 */
abstract class PostgresToolCommand extends Command
{
    public function __construct(
        protected readonly Connection $connection,
    ) {
        parent::__construct();
    }

    /** @return list<string> */
    protected function connectionArguments(): array
    {
        $params = $this->connection->getParams();
        $arguments = [];

        foreach (['host' => '--host', 'port' => '--port', 'user' => '--username', 'dbname' => '--dbname'] as $key => $flag) {
            $value = $params[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                $arguments[] = $flag;
                $arguments[] = $value;
            } elseif (\is_int($value)) {
                $arguments[] = $flag;
                $arguments[] = (string) $value;
            }
        }

        return $arguments;
    }

    protected function exportPassword(Process $process): void
    {
        $password = $this->connection->getParams()['password'] ?? null;
        if (\is_string($password) && $password !== '') {
            $process->setEnv(['PGPASSWORD' => $password]);
        }
    }
}
