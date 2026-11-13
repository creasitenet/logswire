<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\Project\ProjectService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProjectServiceTest extends TestCase
{
    public function testDsnEndsWithProjectSlug(): void
    {
        $project = (new Project())->setName('Demo')->setSlug('demo');

        $dsn = $this->service('http://localhost:8080')->buildDsn($project);

        // SDKs append "/api/<projectId>/envelope/" themselves: the DSN must end
        // with the project segment, without "/api/" and without trailing slash.
        self::assertSame(sprintf('http://%s@localhost:8080/demo', $project->getPublicKey()), $dsn);
        self::assertSame('/demo', parse_url($dsn, \PHP_URL_PATH));
        self::assertStringNotContainsString('/api/', $dsn);
    }

    public function testDsnKeepsPortAndSchemeFromPublicUrl(): void
    {
        $project = (new Project())->setName('Demo')->setSlug('demo');

        $dsn = $this->service('https://logs.example.com:8443')->buildDsn($project);

        self::assertSame(
            sprintf('https://%s@logs.example.com:8443/demo', $project->getPublicKey()),
            $dsn,
        );
    }

    private function service(string $publicUrl): ProjectService
    {
        return new ProjectService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ProjectRepository::class),
            $publicUrl,
        );
    }
}
