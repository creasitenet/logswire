<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\Table(name: 'issues')]
#[ORM\UniqueConstraint(name: 'uniq_issues_project_fingerprint', columns: ['project_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_issues_project_last_seen', columns: ['project_id', 'last_seen_at'])]
#[ORM\Index(name: 'idx_issues_project_status', columns: ['project_id', 'status'])]
class Issue
{
    public const STATUS_UNRESOLVED = 'unresolved';
    public const STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    private string $fingerprint = '';

    #[ORM\Column(length: 512)]
    private string $title = '';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_UNRESOLVED;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $level = 4;

    #[ORM\Column]
    private int $count = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $culprit = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastEnvironment = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $lastRelease = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = mb_substr($fingerprint, 0, 255);

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = mb_substr($title, 0, 512);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;

        return $this;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): self
    {
        $this->count = $count;

        return $this;
    }

    public function getCulprit(): ?string
    {
        return $this->culprit;
    }

    public function setCulprit(?string $culprit): self
    {
        $this->culprit = $culprit !== null ? mb_substr($culprit, 0, 255) : null;

        return $this;
    }

    public function getLastEnvironment(): ?string
    {
        return $this->lastEnvironment;
    }

    public function setLastEnvironment(?string $lastEnvironment): self
    {
        if ($lastEnvironment === null || trim($lastEnvironment) === '') {
            $this->lastEnvironment = null;
        } else {
            $this->lastEnvironment = mb_substr(trim($lastEnvironment), 0, 64);
        }

        return $this;
    }

    public function getLastRelease(): ?string
    {
        return $this->lastRelease;
    }

    public function setLastRelease(?string $lastRelease): self
    {
        if ($lastRelease === null || trim($lastRelease) === '') {
            $this->lastRelease = null;
        } else {
            $this->lastRelease = mb_substr(trim($lastRelease), 0, 128);
        }

        return $this;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }
}
