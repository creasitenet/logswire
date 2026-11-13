<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_project_received', columns: ['project_id', 'received_at'])]
#[ORM\Index(name: 'idx_events_fingerprint', columns: ['project_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_events_issue_received', columns: ['issue_id', 'received_at'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Issue $issue = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $level = 4;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exceptionValue = null;

    /**
     * Fingerprint of the normalized stack frames, kept even when the full payload
     * is trimmed, so stack divergence can still be detected later.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stackHash = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $platform = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $environment = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $releaseTag = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tagsJson = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $sdkName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sdkVersion = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $requestMethod = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $requestUrl = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $osName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $browserName = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $serverName = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $clientIp = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $eventTimestamp = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $payload = null;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?string
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

    public function getIssue(): ?Issue
    {
        return $this->issue;
    }

    public function setIssue(?Issue $issue): self
    {
        $this->issue = $issue;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): self
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

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

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(?string $fingerprint): self
    {
        $this->fingerprint = self::clip($fingerprint, 255);

        return $this;
    }

    public function getExceptionType(): ?string
    {
        return $this->exceptionType;
    }

    public function setExceptionType(?string $exceptionType): self
    {
        $this->exceptionType = self::clip($exceptionType, 255);

        return $this;
    }

    public function getExceptionValue(): ?string
    {
        return $this->exceptionValue;
    }

    public function setExceptionValue(?string $exceptionValue): self
    {
        $this->exceptionValue = $exceptionValue;

        return $this;
    }

    public function getStackHash(): ?string
    {
        return $this->stackHash;
    }

    public function setStackHash(?string $stackHash): self
    {
        $this->stackHash = $stackHash;

        return $this;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(?string $platform): self
    {
        $this->platform = self::clip($platform, 64);

        return $this;
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    public function setEnvironment(?string $environment): self
    {
        $this->environment = self::clip($environment, 64);

        return $this;
    }

    public function getReleaseTag(): ?string
    {
        return $this->releaseTag;
    }

    public function setReleaseTag(?string $releaseTag): self
    {
        $this->releaseTag = self::clip($releaseTag, 128);

        return $this;
    }

    public function getTagsJson(): ?string
    {
        return $this->tagsJson;
    }

    public function setTagsJson(?string $tagsJson): self
    {
        $this->tagsJson = $tagsJson;

        return $this;
    }

    public function getSdkName(): ?string
    {
        return $this->sdkName;
    }

    public function setSdkName(?string $sdkName): self
    {
        $this->sdkName = self::clip($sdkName, 128);

        return $this;
    }

    public function getSdkVersion(): ?string
    {
        return $this->sdkVersion;
    }

    public function setSdkVersion(?string $sdkVersion): self
    {
        $this->sdkVersion = self::clip($sdkVersion, 64);

        return $this;
    }

    public function getRequestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function setRequestMethod(?string $requestMethod): self
    {
        $this->requestMethod = self::clip($requestMethod, 10);

        return $this;
    }

    public function getRequestUrl(): ?string
    {
        return $this->requestUrl;
    }

    public function setRequestUrl(?string $requestUrl): self
    {
        $this->requestUrl = self::clip($requestUrl, 512);

        return $this;
    }

    public function getOsName(): ?string
    {
        return $this->osName;
    }

    public function setOsName(?string $osName): self
    {
        $this->osName = self::clip($osName, 64);

        return $this;
    }

    public function getBrowserName(): ?string
    {
        return $this->browserName;
    }

    public function setBrowserName(?string $browserName): self
    {
        $this->browserName = self::clip($browserName, 64);

        return $this;
    }

    public function getServerName(): ?string
    {
        return $this->serverName;
    }

    public function setServerName(?string $serverName): self
    {
        $this->serverName = self::clip($serverName, 128);

        return $this;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): self
    {
        $this->clientIp = self::clip($clientIp, 45);

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = self::clip($userAgent, 512);

        return $this;
    }

    public function getEventTimestamp(): ?\DateTimeImmutable
    {
        return $this->eventTimestamp;
    }

    public function setEventTimestamp(?\DateTimeImmutable $eventTimestamp): self
    {
        $this->eventTimestamp = $eventTimestamp;

        return $this;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function setPayload(?string $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Keeps payload-provided values within their column length so a hostile or
     * verbose SDK cannot trigger a database error on insert.
     */
    private static function clip(?string $value, int $length): ?string
    {
        if ($value === null || mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length);
    }
}
