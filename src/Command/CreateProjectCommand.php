<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Project\ProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:project:create', description: 'Create a project and print its DSN parts (for smoke tests and ops).')]
final class CreateProjectCommand extends Command
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        try {
            $project = $this->projects->create($name);
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $dsn = $this->projects->buildDsn($project);
        $io->success(sprintf('Project "%s" created.', $project->getName()));
        $io->writeln([
            'slug:       '.$project->getSlug(),
            'public_key: '.$project->getPublicKey(),
            'dsn:        '.$dsn,
        ]);

        return Command::SUCCESS;
    }
}
