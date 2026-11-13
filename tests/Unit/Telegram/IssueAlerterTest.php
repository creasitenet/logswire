<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Entity\Event;
use App\Entity\Issue;
use App\Entity\Project;
use App\Service\Telegram\IssueAlerter;
use App\Service\Telegram\TelegramChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class IssueAlerterTest extends TestCase
{
    public function testNotifiesOnErrorLevelNewIssue(): void
    {
        $telegram = $this->createMock(TelegramChannel::class);
        $telegram->expects(self::once())
            ->method('notify')
            ->with(self::logicalAnd(
                self::stringContains('[errors] error in My Project'),
                self::stringContains('RuntimeException: boom'),
            ))
            ->willReturn(['ok' => true, 'sent' => true, 'detail' => 'ok']);

        $alerter = new IssueAlerter($telegram, new NullLogger());
        $alerter->onNewIssue($this->project(), $this->issue(), $this->event(8));
    }

    public function testEscapesUserDataInMarkdownMessage(): void
    {
        $telegram = $this->createMock(TelegramChannel::class);
        $telegram->expects(self::once())
            ->method('notify')
            ->with(self::stringContains('*what\_now\**'))
            ->willReturn(['ok' => true, 'sent' => true, 'detail' => 'ok']);

        $issue = (new Issue())
            ->setTitle('what_now*')
            ->setCulprit('Foo.php::run')
            ->setLevel(8);

        $alerter = new IssueAlerter($telegram, new NullLogger());
        $alerter->onNewIssue($this->project(), $issue, $this->event(8));
    }

    public function testSkipsIssuesBelowErrorLevel(): void
    {
        $telegram = $this->createMock(TelegramChannel::class);
        $telegram->expects(self::never())->method('notify');

        $alerter = new IssueAlerter($telegram, new NullLogger());
        $alerter->onNewIssue($this->project(), $this->issue(), $this->event(6));
    }

    public function testSwallowsNotifyFailuresAndLogsWarning(): void
    {
        $telegram = $this->createMock(TelegramChannel::class);
        $telegram->expects(self::once())
            ->method('notify')
            ->willThrowException(new \RuntimeException('network down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('IssueAlerter'), self::arrayHasKey('error'));

        $alerter = new IssueAlerter($telegram, $logger);
        $alerter->onNewIssue($this->project(), $this->issue(), $this->event(10));
    }

    public function testDoesNotRenotifyReopenedByDefault(): void
    {
        $telegram = $this->createMock(TelegramChannel::class);
        $telegram->expects(self::never())->method('notify');

        $alerter = new IssueAlerter($telegram, new NullLogger());
        $alerter->onReopenedIssue($this->project(), $this->issue(), $this->event(8));
    }

    public function testRenotifiesReopenedWhenEnabled(): void
    {
        $telegram = $this->createMock(TelegramChannel::class);
        $telegram->expects(self::once())
            ->method('notify')
            ->with(self::logicalAnd(
                self::stringContains('[errors] error in My Project'),
                self::stringContains('reopened'),
            ))
            ->willReturn(['ok' => true, 'sent' => true, 'detail' => 'ok']);

        $alerter = new IssueAlerter($telegram, new NullLogger(), true);
        $alerter->onReopenedIssue($this->project(), $this->issue(), $this->event(8));
    }

    private function project(): Project
    {
        return (new Project())->setName('My Project')->setSlug('my-project');
    }

    private function issue(): Issue
    {
        return (new Issue())
            ->setTitle('RuntimeException: boom')
            ->setCulprit('Foo.php::run')
            ->setLevel(8);
    }

    private function event(int $level): Event
    {
        return (new Event())->setLevel($level);
    }
}
