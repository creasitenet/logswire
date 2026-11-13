<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Project>
 */
final class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function findOneById(Uuid $id): ?Project
    {
        return $this->findOneBy(['id' => $id->toRfc4122()]);
    }

    public function findOneBySlug(string $slug): ?Project
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<Project>
     */
    public function findAllForDashboard(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }
}
