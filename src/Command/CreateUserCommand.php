<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\User\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:owner:create', description: 'Create the first owner (admin) user.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserService $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Local username (lowercase letters, digits, . _ -)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, sprintf('Password (%d characters minimum)', UserService::MIN_PASSWORD_LENGTH))
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN (default for first user)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $password = (string) ($input->getOption('password') ?? '');
        $admin = (bool) $input->getOption('admin');

        try {
            $user = $this->users->create($username, $password, $admin);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'User "%s" created (roles: %s).',
            $user->getUsername(),
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
