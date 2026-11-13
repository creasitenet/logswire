<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\User\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:password', description: 'Reset a local user password (CLI only).')]
final class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserService $userService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Existing username')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, sprintf('New password (%d characters minimum)', UserService::MIN_PASSWORD_LENGTH));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = strtolower(trim((string) $input->getArgument('username')));
        $password = (string) ($input->getOption('password') ?? '');

        $user = $this->users->findOneByUsername($username);
        if ($user === null) {
            $io->error(sprintf('User "%s" not found. List users: bin/console dbal:run-sql "SELECT username FROM users"', $username));

            return Command::FAILURE;
        }

        try {
            $this->userService->resetPassword($user, $password);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->success(sprintf('Password updated for "%s".', $username));

        return Command::SUCCESS;
    }
}
