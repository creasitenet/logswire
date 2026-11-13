<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class ProjectService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projects,
        private readonly string $publicUrl,
    ) {
    }

    /** @return list<Project> */
    public function list(): array
    {
        return $this->projects->findAllForDashboard();
    }

    public function setIngestActive(Project $project, bool $active): Project
    {
        $project->setActive($active);
        $this->em->flush();

        return $project;
    }

    public function get(Uuid $id): Project
    {
        $project = $this->projects->findOneById($id);
        if (!$project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }

    public function create(string $name): Project
    {
        $name = self::validateName($name);

        $slug = $this->uniqueSlug(self::slugify($name));
        $project = (new Project())
            ->setName($name)
            ->setSlug($slug);

        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    public function update(Project $project, string $name, ?bool $active = null): Project
    {
        $project->setName(self::validateName($name));
        if ($active !== null) {
            $project->setActive($active);
        }
        $this->em->flush();

        return $project;
    }

    public function delete(Project $project): void
    {
        $this->em->remove($project);
        $this->em->flush();
    }

    public function regenerateDsn(Project $project): Project
    {
        $project->rotatePublicKey();
        $this->em->flush();

        return $project;
    }

    /** @return array<string, mixed> */
    public function viewData(Project $project): array
    {
        $dsn = $this->buildDsn($project);

        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'slug' => $project->getSlug(),
            'public_key' => $project->getPublicKey(),
            'dsn' => $dsn,
            'active' => $project->isActive(),
            'created_at' => $project->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'snippet_php' => self::phpSnippet($dsn),
            'snippet_node' => self::nodeSnippet($dsn),
        ];
    }

    public function buildDsn(Project $project): string
    {
        $parts = parse_url($this->publicUrl);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException('APP_PUBLIC_URL is invalid.');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        // Sentry SDKs append "/api/<projectId>/envelope/" themselves, so the DSN
        // must end with the project segment (no "/api/" prefix, no trailing slash).
        return sprintf(
            '%s://%s@%s/%s',
            $scheme,
            $project->getPublicKey(),
            $host,
            rawurlencode($project->getSlug()),
        );
    }

    private static function phpSnippet(string $dsn): string
    {
        $safe = str_replace(['\\', "'"], ['\\\\', "\\'"], $dsn);

        return "\\Sentry\\init([\n    'dsn' => '{$safe}',\n]);\n";
    }

    private static function nodeSnippet(string $dsn): string
    {
        $safe = str_replace(['\\', "'"], ['\\\\', "\\'"], $dsn);

        return "Sentry.init({\n  dsn: '{$safe}',\n});\n";
    }

    private static function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BadRequestHttpException('Name is required (max 120 chars).');
        }

        return $name;
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'project';
        }

        return substr($slug, 0, 60);
    }

    private function uniqueSlug(string $base): string
    {
        $candidate = $base;
        $i = 2;
        while ($this->projects->findOneBySlug($candidate) !== null) {
            $suffix = '-'.$i;
            $candidate = substr($base, 0, 60 - \strlen($suffix)).$suffix;
            ++$i;
        }

        return $candidate;
    }
}
